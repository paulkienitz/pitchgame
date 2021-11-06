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

Third checkin (and a little followup I'm not counting) is prerelease 0.3, which adds a moderators’ page for spam abatement.
Internally, started adding type hinting, which would increase over several checkins.

Fourth checkin, prerelease 0.4, added no significant features but checked a lot of small updates off of the todo list.

Fifth checkin, prerelease 0.5, adds moderator review of a user's full history, with options to block the user or purge their entire contribution.

Sixth through ninth checkins add improved moderator ability to review user histories and recaptcha support, plus fixes and refactors.
I'm moving to a finer grained checkin schedule now and not using prerelease numbers.

I'll call it prerelease 0.6 at the point where I started adding team features.
None of them are ready to use yet, but one thing it does add is a prompt to make new players give a name, which is mandatory in team mode and can be required in non-team mode by setting a constant.

Yet to be added are more cosmetics, proper login (maybe including SSO), and the team play feature.

----

The normal path through the game is as follows.  The number in brackets is the one used internally to designate the view state.

[0] The initial state of the screen displays a welcome message and prompts you for a noun, a verb, and a noun.

[1] After saving your noun, verb, and noun, the form presents a different noun, verb, and noun, and prompts you to give a movie title and description — a pitch — based on that brief idea.  You can also include an optional signature.

[2] After saving your pitch, it lists several previous pitches from other players, and lets you rate each one from one to four stars (or mark them as spam).

[4/5] After submitting your ratings, or setting no ratings and just clicking a continuation button, it shows a thank you message and prompts you for a noun, verb, and noun again, as in step 0, thereby closing the loop.

Abnormal paths can add these steps:

[3] If it runs out of movie pitches that you have neither written nor rated, then instead of showing you pitches to rate, it instead shows you pitches you've already rated with at least three stars... “old favorites”.  (The game currently fails if none are found.)

[6] Step 1 offers you a link to complain about the words you received.  This link takes you to a page where you can mark any or all of the three words as not a valid noun or not a valid verb.  After you do so it takes you back to step 1, with any word you flagged replaced with a new one.

Validation errors reprompt you with the same screen, with messages about required fields or whatever else the issue might be.
Database errors exit to a screen which, for now, displays an exception message openly, followed by a log of database activity in the current postback.
The former is also written to the server error log.

The moderation page starts a list of the ten newest pending moderation requests, each being answerable with a set of radio buttons.
The same page can also serve content to SPARE to insert into a popup for viewing stats about a user, so you can see if they have a record of prior bad behavior.
From that page you can follow a link to a full history of all contributions made by that user, flagging additional trouble spots.
If that history shows a pattern of abuse you can block them, and if it also shows little positive contribution, you can bulk delete the whole list.
If no moderation requests are left, the starting page lists users who have had submissions deleted, so that you can review their histories.

### How to set up

You'll need a host with PHP and MySql.
Use "pitchgame tables.sql" to create the database, but don't upload that file to the web host.
Configure your database password in php.ini as mysqli.default_pw.
Use .htaccess or equivalent to forbid downloading php.ini or .htaccess itself.
Also use it to make pitchgame.php the default index page in its directory, if that's desirable, i.e. if the game is in its own separate directory.
Adjust the constants at the top of pitchdata.php if you want to use recaptcha.
Then upload the other files and test it out.
