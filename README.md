# The Movie Pitch Game
### a web-based game of fun and creativity for movie buffs, implemented in PHP

_No license yet.  Terms of sharing are yet to be decided.  For now, do not use._
_EXCEPTION: the file sql.php is free of all licenses and available to use in any way._

First checkin is prerelease version 0.1, which is the first to have a complete cycle of forms representing normal progress through the game.
It does not yet have the side branches which would be used for reporting bad inputs for moderation.
It lacks almost all cosmetics, except on one page.
A page of background and instructions about the game is written but is not yet accessible for display.

Second checkin is prerelease version 0.2, in which the game is close to complete for ordinary players.
It adds nicer cosmetics, a closeable popup for the instructions, the ability to reject spam words, and tracking of moderation requests.
Some internal features, such as logging of database activity, have been improved.  Exceptions are recorded in the system's normal PHP error log.
Yet to be added are more cosmetics, admin pages for handling spam moderation, and better defense against the back and refresh buttons.

The normal path through the game is as follows:
0. The initial state of the screen displays a welcome message and prompts you for a noun, a verb, and a noun.
1. After saving your noun, verb, and noun, the form presents a different noun, verb, and noun, and prompts you to give a movie title and description — a pitch — based on that brief idea.  You can also include an optional signature.
2. After saving your pitch, it lists several previous pitches from other players, and lets you rate each one from one to four stars (or mark them as spam).
4. After submitting your ratings, or setting no ratings and just clicking a continuation button, it shows a thank you message and prompts you for a noun, verb, and noun again, as in step 0, thereby closing the loop.

Abnormal paths can add these steps:
3. If it runs out of movie pitches that you have neither written nor rated, then instead of showing you pitches to rate, it instead shows you pitches you've already rated with at least three stars... “old favorites”.  (The game currently fails if none are found.)
6. Step 1 offers you a link to complain about the words you received.  This link takes you to a page where you can mark any or all of the three words as not a valid noun or not a valid verb.  After you do so it takes you back to step 1, with any word you flagged replaced with a new one.

Validation errors reprompt you with the same screen, with messages about required fields or whatever else the issue might be.
Database errors exit to a screen which, for now, displays an exception message openly, followed by a log of database activity in the current postback.  The former is (in theory) also written to the server error log.
