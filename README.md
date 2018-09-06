# Automated Voice Surveys
### ⏱ 15 min build time 

## Why build automated voice surveys?

Surveys are a great way to gather feedback about a product or a service. In this MessageBird Developer guide, we'll look at a company that wants to collect surveys over the phone by providing their customers a feedback number that they can call and submit their opinion as voice messages that the company's support team can listen to on a website and incorporate that feedback into the next version of the product. This team should be able to focus their attention on the input on their own time instead of having to wait and answer calls. Therefore, the feedback collection itself is fully automated. 

## Getting Started

The sample application is built in PHP using the [Slim](https://packagist.org/packages/slim/slim) framework. You can download or clone the complete source code from the [MessageBird Developer Guides GitHub repository](https://github.com/messagebirdguides/automated-surveys-guide-php) to run the application on your computer and follow along with the guide. To run the sample, you need to have PHP installed on your machine. If you're using a Mac, PHP is already installed. For Windows, you can [get it from windows.php.net](https://windows.php.net/download/). Linux users, please check your system's default package manager. You also need Composer, which is available from [getcomposer.org](https://getcomposer.org/download/), to install application dependencies.

Let's get started by opening the directory of the sample application and running the following command to install the dependencies:

````bash
composer install
````

The sample application uses a relational database to store survey participants and their responses. It is configured to use a single-file [SQLite](https://www.sqlite.org/) database, which is natively supported by PHP through PDO. This means that it works out of the box without the need to configure an external RDBMS like MySQL. Run the following helper command to create the `survey.sqlite` file as an empty database with the required schema:

````bash
php init.php
````

## Designing the Call Flow

Call flows in MessageBird are sequences of steps. Each step can be a different action, such as playing an audio file, speaking words through text-to-speech (TTS), recording the caller's voice or transferring the call to another party. The call flow for this survey application alternates two types of actions: saying the question (`say` action) and recording an answer (`record` action). Other action types are not required. The whole flow begins with a short introduction text and ends on a "Thank you" note, both of which are implemented as `say` actions.

The survey application generates the call flow dynamically through PHP code and provides it on a webhook endpoint as a JSON response that MessageBird can parse. It does not, however, return the complete flow at once. The generated steps always end on a `record` action with the `onFinish` attribute set to the same webhook endpoint URL. This approach simplifies the collection of recordings because whenever the caller provides an answer, an identifier for the recording is sent with the next webhook request. The endpoint will then store information about the answer to the question and return additional steps: either the next question together with its answer recording step or, if the caller has reached the end of the survey, the final "Thank you" note.

The sample implementation contains only a single survey. For each participant, we create an entry in the `participants` database table that includes a unique MessageBird-generated identifier for the call and their number. Responses are stored in the `responses` database table which contains a reference to the former table through a foreign key (`participant_id`). As the webhook is requested multiple times for each caller, once in the beginning and once for each answer they record, the count of rows in `responses` for a `participant_id` indicates their position within the survey and determines the next step.

All questions are stored as an array in the file `questions.json` to keep them separate from the implementation. The following declaration reads those questions and makes them available on Slim's dependency injection container:

````php
// Load survey questions from JSON file
$container['questions'] = function() {
    return json_decode(file_get_contents(__DIR__.'/questions.json'), true);
};
````

## Prerequisites for Receiving Calls

### Overview

Participants take part in a survey by calling a dedicated virtual phone number. MessageBird accepts the call and contacts the application on a _webhook URL_, which you assign to your number on the MessageBird Dashboard using a flow. A [webhook](https://en.wikipedia.org/wiki/Webhook) is a URL on your site that doesn't render a page to users but is like an API endpoint that can be triggered by other servers. Every time someone calls that number, MessageBird checks that URL for instructions on how to interact with the caller.

### Exposing your Development Server with ngrok

When working with webhooks, an external service like MessageBird needs to access your application, so the URL must be public. During development, though, you're typically working in a local development environment that is not publicly available. There are various tools and services available that allow you to quickly expose your development environment to the Internet by providing a tunnel from a public URL to your local machine. One of the most popular tools is [ngrok](https://ngrok.com).

You can [download ngrok here for free](https://ngrok.com/download) as a single-file binary for almost every operating system, or optionally sign up for an account to access additional features.

You can start a tunnel by providing a local port number on which your application runs. We will run our PHP server on port 8080, so you can launch your tunnel with this command:

````bash
ngrok http 8080
````

After you've launched the tunnel, ngrok displays your temporary public URL along with some other information. We'll need that URL in a minute.

![ngrok](/assets/images/screenshots/automatedsurveys-php/ngrok.png)

Another common tool for tunneling your local machine is [localtunnel.me](https://localtunnel.me), which you can have a look at if you're facing problems with ngrok. It works in virtually the same way but requires you to install [NPM](https://www.npmjs.com/) first.

### Getting an Inbound Number

A requirement for programmatically taking voice calls is a dedicated inbound number. Virtual telephone numbers live in the cloud, i.e., a data center. MessageBird offers numbers from different countries for a low monthly fee. Here's how to purchase one:

1. Go to the [Numbers](https://dashboard.messagebird.com/en/numbers) section of your MessageBird account and click **Buy a number**.

2. Choose the country in which you and your customers are located and make sure the _Voice_ capability is selected.

3. Choose one number from the selection and the duration for which you want to pay now. ![Buy a number screenshot](/assets/images/screenshots/automatedsurveys-php/buy-a-number.png)

4. Confirm by clicking **Buy Number**.

Excellent, you have set up your first virtual number!

### Connecting the Number to your Application

So you have a number now, but MessageBird has no idea what to do with it. That's why you need to define a _Flow_ next that ties your number to your webhook:

1. Open the Flow Builder and click **Create new flow**.

2. In the following dialog, choose **Create Custom Flow**.

3. Give your flow a name, such as "Survey Participation", select _Phone Call_ as the trigger and continue with **Next**. ![Create Flow, Step 1](/assets/images/screenshots/automatedsurveys-php/create-flow-1.png)

5. Configure the trigger step by ticking the box next to your number and click **Save**. ![Create Flow, Step 2](/assets/images/screenshots/automatedsurveys-php/create-flow-2.png)

6. Press the small **+** to add a new step to your flow and choose **Fetch call flow from URL**. ![Create  Flow, Step 3](/assets/images/screenshots/automatedsurveys-php/create-flow-3.png)

7. Paste the ngrok base URL into the form and append `/callStep` to it - this is the name of our webhook handler route. Click **Save**. ![Create Flow, Step 4](/assets/images/screenshots/automatedsurveys-php/create-flow-4.png)

8. Hit **Publish** and your flow becomes active!

## Implementing the Call Steps

The route `$app->map(['GET','POST'], '/callStep')` in `index.php` contains the implementation of the survey call flow. The mapping with two HTTP verbs is required because the first request to fetch the call flow uses GET and subsequent requests that include recording information use POST. The implementation starts with reading the input from the query parameters sent by MessageBird as part of the webhook (i.e., call flow fetch) request, `callID` and `source` (= telephone number of the caller):

````php
$app->map(['GET','POST'], '/callStep', function($request, $response) {
    // Read query input sent from MessageBird
    $callId = $request->getQueryParam('callID');
    $number = $request->getQueryParam('source');
````

Then, we set up the basic structure for a JSON call flow object called `$flow`, which we'll extend depending on where we are within our survey:

````php
    // Prepare a Call Flow that can be extended
    $flow = [
        'title' => "Survey Call Step",
        'steps' => []
    ];
````

Next, we use an SQL SELECT query to try and find an existing call:

````php
    // Find a database entry for the call
    $stmt = $this->db->prepare('SELECT * FROM participants WHERE callId = :callId');
    $stmt->execute([ 'callId' => $callId ]);
    $participant = $stmt->fetch();
````

If none is found, we create a new participant using an INSERT and set the ID (i.e., array index) of the next question to _0_:

````php
    if ($participant === false) {
        // Create new participant database entry
        $stmt = $this->db->prepare('INSERT INTO participants (callId, number) VALUES (:callId, :number)');
        $stmt->execute([ 'callId' => $callId, 'number' => $number ]);
        error_log("Created participant.");

        // The person is just starting the survey
        $questionId = 0;
````

If the participant already exists we count the number of responses that the person has given to determine the ID of the next question:

````php
    } else {
        // Participant already exists,
        // determine the next question
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM responses WHERE participant_id = :participantId');
        $stmt->execute([ 'participantId' => $participant['id'] ]);

        $questionId = (int)$stmt->fetchColumn() + 1;
    }
````

In this case we will also have received a JSON payload with data about the previous recording. Participant responses are persisted by inserting them into the `responses` database table. For every answer we store two identifiers from the parsed JSON request body: the `legId` that identifies the caller in a multi-party voice call and is required to fetch the recording, as well as the `id` of the recording itself which we store as `recordingId`:

````php
        // Store the response of the previous question
        $jsonInput = json_decode($request->getBody()->getContents(), true);
        $stmt = $this->db->prepare('INSERT INTO responses (participant_id, legId, recordingId) VALUES (:participantId, :legId, :recordingId)');
        $stmt->execute([ 'participantId' => $participant['id'],
            'legId' => $jsonInput['legId'],
            'recordingId' => $jsonInput['id'] ]);
        error_log("Added response.");
    }
````

Now it's time to ask a question. First, however, we check if we reached the end of the survey. That is determined by whether the question index equals the length of the questions list and therefore is out of bounds of the array, which means there are no further questions. If so, we thank the caller for their participation:

````php
    if ($questionId == count($this->questions)) {
        // All questions have been answered
        $flow['steps'][] = say("You have completed our survey. Thank you for participating!");
````

You'll notice the `say()` function. It is a small helper function we've declared separately in the initial section of `index.php` to simplify the creation of `say` steps as we need them multiple times in the application. The function returns the action in the format expected by MessageBird so it can be added to the `steps` of a flow using the `[]` syntax, as seen above.

A function like this allows setting options for `say` actions at a central location. You can modify it if you want to, for example, specify another language or voice.

````php
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
````

Back in the route, there's an else-block that handles all questions other than the last. There's another nested if-statement in it, though, to treat the first question, as we need to read a welcome message to our participant _before_ the question:

````php
    } else {
        if ($questionId == 0) {
            // Before first question, say welcome
            $flow['steps'][] = say("Welcome to our survey! You will be asked " . count($this->questions) . " questions. The answers will be recorded. Speak your response for each and press any key on your phone to move on to the next question. Here is the first question:");
        }
````

Finally, here comes the general logic used for each question:
* Ask the question using `say`.
* Request a recording.

````php
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
````

The `record` step is configured so that it finishes when the caller presses any key on their phone's keypad (`finishOnKey` attribute) or when MessageBird detects 10 seconds of silence (`timeout` attribute). By specifying the URL with the `onFinish` attribute we can make sure that the recording data is sent back to our route and that we can send additional steps to the caller. Note that the `/callStep` route has a name attached to it at the end using `->setName('callStep');` so we can use the Slim router's `pathFor()` function to build the URL. There's a small custom middleware defined in the upper section of `index.php` that sets a base path on the router to make sure that `pathFor()` generates an absolute URL with protocol and hostname information from the request to ensure that it works wherever the application is deployed and also behind the tunnel.

Only one tiny part remains: the last step in each webhook request is sending back a JSON response based on the `$flow` object:

````php
    return $response->withJson($flow);
````

## Building an Admin View

The survey application also contains an admin view that allows us to view the survey participants and listen to their responses. The implementation of the `$app->get('/admin')` route is straightforward, it essentially loads everything from the database plus the questions and adds it to the data available for a [Twig](https://twig.symfony.com/) template.

The template, which you can see in `views/admin.html.twig`, contains a basic HTML structure with a three-column table. Inside the table, two nested loops over the participants and their responses add a line for each answer with the number of the caller, the question and a "Listen" button that plays it back.

Let's have a more detailed look at the implementation of this "Listen" button. On the frontend, the button calls a Javascript function called `playAudio()` with the `callId`, `legId` and `recordingId` inserted through Twig expressions:

````html
<button onclick="playAudio('{{p.callId}}','{{r.legId}}','{{r.recordingId}}')">Listen</button>
````

The implementation of that function dynamically generates an invisible, auto-playing HTML5 audio element:

````javascript
function playAudio(callId, legId, recordingId) {
    document.getElementById('audioplayer').innerHTML
        = '<audio autoplay="1"><source src="/play/' + callId
            + '/' + legId + '/' + recordingId
            + '" type="audio/wav"></audio>';
}
````

As you can see, the WAV audio is requested from a route of the survey application. This route acts as a proxy server that fetches the audio from MessageBird's API and forwards it to the frontend. This architecture is necessary because we need a MessageBird API key to fetch the audio but don't want to expose it on the client-side of our application. We use [Guzzle](https://packagist.org/packages/guzzlehttp/guzzle) to make the API call and add the API key as an HTTP header:

````php
$app->get('/play/{callId}/{legId}/{recordingId}', function($request, $response, $params) {
    // Make a proxy request to the audio file on the API
    $client = new GuzzleHttp\Client;
    return $client->get('https://voice.messagebird.com/calls/' . $params['callId']
        . '/legs/' . $params['legId']
        . '/recordings/ ' . $params['recordingId'] . '.wav', [
            'headers' => [
                'Authorization' => 'AccessKey '.getenv('MESSAGEBIRD_API_KEY')
            ]
        ]);
});
````

As you can see, the API key is taken from an environment variable. To provide the key in the environment variable, [phpdotenv](https://packagist.org/packages/vlucas/phpdotenv) is used. We've prepared an `env.example` file in the repository, which you should rename to `.env` and add the required information. Here's an example:

````env
MESSAGEBIRD_API_KEY=YOUR-API-KEY
````

You can create or retrieve a live API key from the [API access (REST) tab](https://dashboard.messagebird.com/en/developers/access) in the _Developers_ section of your MessageBird account.

## Testing your Application

Check again that you have set up your number correctly with a flow to forward incoming phone calls to a ngrok URL and that the tunnel is still running. Remember, whenever you start a fresh tunnel, you'll get a new URL, so you have to update the flows accordingly.

To start the application you have to enter another command, but your existing console window is already busy running your tunnel. Therefore you need to open another one. On a Mac you can press _Command_ + _Tab_ to open a second tab that's already pointed to the correct directory. With other operating systems you may have to resort to open another console window manually. Either way, once you've got a command prompt, type the following to start the application:

````bash
php -S 0.0.0.0:8080 index.php
````

Now, take your phone and dial your survey number. You should hear the welcome message and the first question. Speak an answer and press any key. At that moment you should see some debug output (generated with `error_log()` in the console. Open http://localhost:8080/admin to see your call as well. Continue interacting with the survey. In the end, you can refresh your browser and listen to all the answers you recorded within your phone call.

Congratulations, you just deployed a survey system with MessageBird!

## Supporting Outbound Calls

The application was designed for incoming calls where survey participants call a virtual number and can provide their answers. The same code works for an outbound call scenario as well. There are only two things you have to do:
- Change the first line of the `/callStep` route to use `destination` instead of `source` as the number.
- Start a call through the API or other means and use a call flow that contains a `fetchCallFlow` step pointing to your webhook route.

## Nice work!

You now have a running integration of MessageBird's Voice API!

You can now leverage the flow, code snippets and UI examples from this tutorial to build your own automated voice survey. Don't forget to download the code from the [MessageBird Developer Guides GitHub repository](https://github.com/messagebirdguides/automated-surveys-guide-php).

## Next steps

Want to build something similar but not quite sure how to get started? Please feel free to let us know at support@messagebird.com, we'd love to help!