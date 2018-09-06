<?php

require_once "vendor/autoload.php";

// Create app
$app = new Slim\App;

// Load configuration with dotenv
$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

// Get container
$container = $app->getContainer();

// Register Twig component on container to use view templates
$container['view'] = function() {
    return new Slim\Views\Twig('views');
};

// Initialize database
$container['db'] = function() {
    return new PDO('sqlite:ridesharing.sqlite');
};

// Load and initialize MesageBird SDK
$container['messagebird'] = function() {
    return new MessageBird\Client(getenv('MESSAGEBIRD_API_KEY'));
};

// Show admin interface
$app->get('/', function($request, $response) {
    // Find proxy numbers
    $stmt = $this->db->query('SELECT number FROM proxy_numbers');
    $proxyNumbers = $stmt->fetchAll();

    // Find current rides
    $stmt = $this->db->query('SELECT c.name AS customer, d.name AS driver, start, destination, datetime, p.number AS number FROM rides r JOIN customers c ON c.id = r.customer_id JOIN drivers d ON d.id = r.driver_id JOIN proxy_numbers p ON p.id = r.number_id');
    $rides = $stmt->fetchAll();

    // Collect customers
    $stmt = $this->db->query('SELECT * FROM customers');
    $customers = $stmt->fetchAll();

    // Collect drivers
    $stmt = $this->db->query('SELECT * FROM drivers');
    $drivers = $stmt->fetchAll();

    // Render template
    return $this->view->render($response, 'admin.html.twig', [
        'proxy_numbers' => $proxyNumbers,
        'rides' => $rides,
        'customers' => $customers,
        'drivers' => $drivers
    ]);
});

// Create a new ride
$app->post('/createride', function($request, $response) {
    // Find customer details
    $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = :id');
    $stmt->execute([ 'id' => $request->getParsedBodyParam('customer') ]);
    $customer = $stmt->fetch();

    // Find driver details
    $stmt = $this->db->prepare('SELECT * FROM drivers WHERE id = :id');
    $stmt->execute([ 'id' => $request->getParsedBodyParam('driver') ]);
    $driver = $stmt->fetch();

    // Find a number that has not been used by the driver or the customer
    $stmt = $this->db->prepare('SELECT * FROM proxy_numbers '
        . 'WHERE id NOT IN (SELECT number_id FROM rides WHERE customer_id = :customer) '
        . 'AND id NOT IN (SELECT number_id FROM rides WHERE driver_id = :driver)');
    $stmt->execute([
        'customer' => $customer['id'],
        'driver' => $driver['id']
    ]);
    $proxyNumber = $stmt->fetch();

    if ($proxyNumber === false) {
        // No number found!
        return "No number available! Please extend your pool.";
    }

    // Store ride in database
    $stmt = $this->db->prepare('INSERT INTO rides (start, destination, datetime, customer_id, driver_id, number_id) VALUES (:start, :destination, :datetime, :customer, :driver, :number)');
    $stmt->execute([
        'start' => $request->getParsedBodyParam('start'),
        'destination' => $request->getParsedBodyParam('destination'),
        'datetime' => $request->getParsedBodyParam('datetime'),
        'customer' => $customer['id'],
        'driver' => $driver['id'],
        'number' => $proxyNumber['id']
    ]);
        
    // Prepare message object
    $message = new MessageBird\Objects\Message;
    $message->originator = $proxyNumber['number'];
    
    // Notify the customer
    $message->recipients = [ $customer['number'] ];
    $message->body = $driver['name'] . " will pick you up at " . $request->getParsedBodyParam('datetime') . ". Reply to this message or call this number to contact the driver.";
    try {
        $this->messagebird->messages->create($message);
    } catch (Exception $e) {
        error_log(get_class($e).": ".$e->getMessage());
    }

    // Notify the driver
    $message->recipients = [ $driver['number'] ];
    $message->body = $customer['name'] . " will wait for you at " . $request->getParsedBodyParam('datetime') . ". Reply to this message or call this number to contact the customer.";
    try {
        $this->messagebird->messages->create($message);
    } catch (Exception $e) {
        error_log(get_class($e).": ".$e->getMessage());
    }

    // Redirect back to previous view
    return $response->withRedirect('/');
});

// Handle incoming messages
$app->post('/webhook', function($request, $response) {
    // Read input sent from MessageBird
    $number = $request->getParsedBodyParam('originator');
    $text = $request->getParsedBodyParam('payload');
    $proxy = $request->getParsedBodyParam('recipient');

    // Find potential rides that fit the numbers
    $stmt = $this->db->prepare('SELECT c.number AS customer_number, d.number AS driver_number, p.number AS proxy_number '
        . 'FROM rides r JOIN customers c ON r.customer_id = c.id JOIN drivers d ON r.driver_id = d.id JOIN proxy_numbers p ON p.id = r.number_id '
        . 'WHERE proxy_number = :proxy AND (driver_number = :number OR customer_number = :number)');
    $stmt->execute([
        'number' => $number,
        'proxy' => $proxy
    ]);
    $row = $stmt->fetch();

    if ($row !== false) {
        // Got a match!

        // Prepare message object
        $message = new MessageBird\Objects\Message;
        $message->originator = $proxy;
        $message->body = $text;

        // Need to find out whether customer or driver sent this and forward to the other side
        if ($number == $row['customer_number'])
            $message->recipients = [ $row['driver_number'] ];
        else
        if ($number == $row['driver_number'])
            $message->recipients = [ $row['customer_number'] ];
                
        // Forward the message through the MessageBird API
        try {
            $this->messagebird->messages->create($message);
            error_log("Forwarded text from " . $number . " to " . $message->recipients[0]);
        } catch (Exception $e) {
            error_log(get_class($e).": ".$e->getMessage());
        }
    } else {
        // Cannot match numbers
        error_log("Could not find a ride for customer/driver " . $number . " that uses proxy " . $proxy . ".");
    }

    // Return any response, MessageBird won't parse this
    return "OK";
});

// Handle incoming calls
$app->get('/webhook-voice', function($request, $response) {
    // Read input sent from MessageBird
    $number = $request->getQueryParam('source');
    $proxy = $request->getQueryParam('destination');

    // Answer will always be XML
    $response = $response->withHeader('Content-Type', 'application/xml')
        ->write('<?xml version="1.0" encoding="UTF-8"?>');

    // Find potential rides that fit the numbers
    $stmt = $this->db->prepare('SELECT c.number AS customer_number, d.number AS driver_number, p.number AS proxy_number '
        . 'FROM rides r JOIN customers c ON r.customer_id = c.id JOIN drivers d ON r.driver_id = d.id JOIN proxy_numbers p ON p.id = r.number_id '
        . 'WHERE proxy_number = :proxy AND (driver_number = :number OR customer_number = :number)');
    $stmt->execute([
        'number' => $number,
        'proxy' => $proxy
    ]);
    $row = $stmt->fetch();

    if ($row !== false) {
        // Got a match!
        
        // Need to find out whether customer or driver sent this and forward to the other side
        $destination = "";
        if ($number == $row['customer_number'])
            $destination = $row['driver_number'];
        else
        if ($number == $row['driver_number'])
            $destination = $row['customer_number'];
                
        // Create call flow to instruct transfer
        error_log("Transferring call to " . $destination);
        $response->write('<Transfer destination="' . $destination . '" mask="true" />');
    } else {
        // Cannot match numbers
        $response->write('<Say language="en-GB" voice="female">Sorry, we cannot identify your transaction. Make sure you call in from the number you registered.</Say>');
    }

    return $response;
});

// Start the application
$app->run();