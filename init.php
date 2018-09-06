<?php

// Create/open the database file
$db = new PDO('sqlite:ridesharing.sqlite');

// Create the data model

// Customers: have ID, name and number
$db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, number TEXT)');
// Drivers: have ID, name and number
$db->exec('CREATE TABLE drivers (id INTEGER PRIMARY KEY, name TEXT, number TEXT)');
// Proxy Numbers: have ID and number
$db->exec('CREATE TABLE proxy_numbers (id INTEGER PRIMARY KEY, number TEXT)');
// Rides: have ID, start, destination and date; are connected to a customer, a driver, and a proxy number
$db->exec('CREATE TABLE rides (id INTEGER PRIMARY KEY, start TEXT, destination TEXT, datetime TEXT, customer_id INTEGER, driver_id INTEGER, number_id INTEGER, FOREIGN KEY (customer_id) REFERENCES customers(id), FOREIGN KEY (driver_id) REFERENCES drivers(id))');

// Insert some data
    
// Create a sample customer for testing
// -> enter your name and number here!
$db->exec('INSERT INTO customers (name, number) VALUES ("Caitlyn Carless", "31970XXXX")');

// Create a sample driver for testing
// -> enter your name and number here!
$db->exec('INSERT INTO drivers (name, number) VALUES ("David Driver", "31970YYYY")');
    
// Create a proxy number
// -> provide a number purchased from MessageBird here
// -> copy the line if you have more numbers
$db->exec('INSERT INTO proxy_numbers (number) VALUES ("31970ZZZZ")');

