<?php

// Create/open the database file
$db = new PDO('sqlite:survey.sqlite');

// Generate schema: 2 tables
$db->exec('CREATE TABLE participants (id INTEGER PRIMARY KEY, callId TEXT, number TEXT)');
$db->exec('CREATE TABLE responses (id INTEGER PRIMARY KEY, participant_id INTEGER, legId TEXT, recordingId TEXT, FOREIGN KEY (participant_id) REFERENCES participants(id))');