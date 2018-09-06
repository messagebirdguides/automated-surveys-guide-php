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
    return new PDO('sqlite:survey.sqlite');
};

// Load survey questions from JSON file
$container['questions'] = function() {
    return json_decode(file_get_contents(__DIR__.'/questions.json'), true);
};

// Create middleware that determines correct base URL
$app->add(function($request, $response, $next) {
    $this->router->setBasePath(
        (($request->getUri()->getScheme() == 'https' || $request->getHeaderLine('X-Forwarded-Proto') == 'https') ? 'https' : 'http')
        . '://' . $request->getUri()->getHost()
    );    
    return $next($request, $response);
});

/**
 * Helper function to generate a "say" call flow step.
 */
function say($payload) {
    return [
        'action' => 'say',
        'options' => [
            'payload' => $payload,
            'voice' => 'male',
            'language' => 'en-US'
        ]
    ];
}

$app->map(['GET','POST'], '/callStep', function($request, $response) {
    // Read query input sent from MessageBird
    $callId = $request->getQueryParam('callID');
    $number = $request->getQueryParam('source');

    // Prepare a Call Flow that can be extended
    $flow = [
        'title' => "Survey Call Step",
        'steps' => []
    ];

    // Find a database entry for the call
    $stmt = $this->db->prepare('SELECT * FROM participants WHERE callId = :callId');
    $stmt->execute([ 'callId' => $callId ]);
    $participant = $stmt->fetch();

    if ($participant === false) {
        // Create new participant database entry
        $stmt = $this->db->prepare('INSERT INTO participants (callId, number) VALUES (:callId, :number)');
        $stmt->execute([ 'callId' => $callId, 'number' => $number ]);
        error_log("Created participant.");

        // The person is just starting the survey
        $questionId = 0;
    } else {
        // Participant already exists,
        // determine the next question
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM responses WHERE participant_id = :participantId');
        $stmt->execute([ 'participantId' => $participant['id'] ]);

        $questionId = (int)$stmt->fetchColumn() + 1;

        // Store the response of the previous question
        $jsonInput = json_decode($request->getBody()->getContents(), true);
        $stmt = $this->db->prepare('INSERT INTO responses (participant_id, legId, recordingId) VALUES (:participantId, :legId, :recordingId)');
        $stmt->execute([ 'participantId' => $participant['id'],
            'legId' => $jsonInput['legId'],
            'recordingId' => $jsonInput['id'] ]);
        error_log("Added response.");
    }

    if ($questionId == count($this->questions)) {
        // All questions have been answered
        $flow['steps'][] = say("You have completed our survey. Thank you for participating!");
    } else {
        if ($questionId == 0) {
            // Before first question, say welcome
            $flow['steps'][] = say("Welcome to our survey! You will be asked " . count($this->questions) . " questions. The answers will be recorded. Speak your response for each and press any key on your phone to move on to the next question. Here is the first question:");
        }
        // Ask next question
        $flow['steps'][] = say($this->questions[$questionId]);

        // Request recording of question
        $flow['steps'][] = [
            'action' => 'record',
            'options' => [
                // Finish either on key press or after 10 seconds of silence
                'finishOnKey' => 'any',
                'timeout' => 10,
                // Send recording to this same call flow URL
                'onFinish' => $this->router->pathFor('callStep')
            ]
        ];
    }

    // Return flow as JSON response
    return $response->withJson($flow);

})->setName('callStep');

$app->get('/admin', function($request, $response) {
    $participants = [];
    
    $query = $this->db->query('SELECT * FROM participants');
    $participantsFromDb = $query->fetchAll();
    foreach ($participantsFromDb as $p) {
        $responses = [];
        
        $stmt = $this->db->query('SELECT * FROM responses WHERE participant_id = :participantId');
        $stmt->execute([ 'participantId' => $p['id'] ]);
        $responsesFromDb = $stmt->fetchAll();
        
        for ($i = 0; $i < count($responsesFromDb); $i++) {
            $responses[] = [
                'question' => $this->questions[$i],
                'legId' => $responsesFromDb[$i]['legId'],
                'recordingId' => $responsesFromDb[$i]['recordingId']
            ];
        }

        $participants[] = [
            'callId' => $p['callId'],
            'number' => $p['number'],
            'responses' => $responses
        ];
    }
    
    return $this->view->render($response, 'admin.html.twig', [
        'participants' => $participants
    ]);
});

$app->get('/play/{callId}/{legId}/{recordingId}', function($request, $response, $params) {
    // Make a proxy request to the audio file on the API
    $client = new GuzzleHttp\Client;
    return $client->get('https://voice.messagebird.com/calls/' . $params['callId']
        . '/legs/' . $params['legId']
        . '/recordings/' . $params['recordingId'] . '.wav', [
            'headers' => [
                'Authorization' => 'AccessKey '.getenv('MESSAGEBIRD_API_KEY')
            ]
        ]);
});

// Start the application
$app->run();