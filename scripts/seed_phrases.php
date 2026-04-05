<?php
/**
 * Seed default chat phrases.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';

$phrases = [
    "I've got a good feeling about this.",
    "This might work.",
    "Okay, I like this.",
    "That helps.",
    "We're in business.",
    "This could be big.",
    "Not bad… not bad.",
    "I'll take it.",
    "That's what I needed.",
    "Things are looking up.",
    "This seems safe.",
    "What could go wrong?",
    "I'm just going to try this.",
    "Let's see how this goes…",
    "Aww DANG IT.",
    "Well, that didn't work.",
    "I did not see that coming.",
    "Okay, okay, I can work with this.",
    "Anyone want to trade?",
    "Your turn!",
    "Nice move.",
    "Whoa.",
    "Good game!",
    "One more round?",
    "I'm thinking…",
    "Hold on, let me think.",
    "Okay, I'm ready.",
    "Deal me in.",
    "I fold.",
    "I'm all in!",
    "Check.",
    "Call.",
    "Raise.",
];

$db = DB::get();

// Clear existing defaults
$db->exec("DELETE FROM chat_phrases WHERE is_default = 1 AND table_id IS NULL");

$stmt = $db->prepare("INSERT INTO chat_phrases (phrase, is_default, table_id) VALUES (?, 1, NULL)");
foreach ($phrases as $phrase) {
    $stmt->execute([$phrase]);
}

echo "Seeded " . count($phrases) . " default chat phrases.\n";
