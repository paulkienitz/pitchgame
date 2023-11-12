# The Movie Pitch Game
### a web-based game of fun and creativity for movie buffs, implemented in PHP

**This game and its source code may be freely used for noncommercial purposes (MIT License with Commons Clause).**

**The game is now live and available to play [here](http://paulkienitz.net/pitchgame/)!**

The normal path through the game is as follows.

0. If you have not launched the game before (or are on a new device), it may ask you to give a name, depending on a config option.
(After the first time, your session is remembered via a long-term cookie.)

1. In regular play, the initial state of the screen displays a welcome message and prompts you for a noun, a verb, and a noun.

2. After saving your noun, verb, and noun, the form presents a different noun, verb, and noun randomly selected from past submissions, and prompts you to give a movie title and description — a pitch — based on the brief idea that they form when used as a sentence.
You can also include an optional signature, which defaults to the name you gave initially (if any).

3. After saving your pitch, it lists several previous pitches from other players, and lets you rate each one from one to four stars (or mark them as spam).

4. After submitting your ratings, or setting no ratings and just clicking a continuation button, it shows a thank you message and prompts you for a noun, verb, and noun again, as in step 1, thereby closing the loop.

Abnormal paths can add these steps:

5. If it runs out of movie pitches that you have neither written nor rated, then instead of showing you pitches to rate, it shows you pitches you've already rated with at least three stars — “old favorites”.

6. Step 2 offers you a link to complain about the words you received.
This link takes you to a page where you can mark any or all of the three words as not a valid noun or not a valid verb.
After you do so it takes you back to step 2, with any word you flagged replaced with a new one.

Validation errors reprompt you with the same screen, with messages about required fields or whatever else the issue might be.
Database errors exit to an apology screen which, for certain users, displays the internal exception message.
This is also written to the server error log.

The moderation page starts as a list of the ten newest pending moderation requests, each being answerable with a set of radio buttons.
The same page can also serve content to `SPARE` to insert into a popup for viewing stats about a user, so you can see if they have a record of prior bad behavior.
From that popup you can follow a link to a full history of all contributions made by that user, flagging additional trouble spots.
If that history shows a pattern of abuse you can block them, and if it also shows little positive contribution, you can bulk delete the whole list.
If no moderation requests are left, the starting page lists users who have had submissions deleted, so that you can review their histories.

By messing directly with the database, certain users (sessions) can be flagged as testers, or as debuggers.
Moderators are shown this status, so they know not to punish invalid inputs that may have been made for test purposes.
Debuggers also get to see internal error messages in the case of an exception, as mentioned, and can view each page's sequence of database transactions via a “show DB log” link.
They also have an option to re-randomize the random selection of words to write a pitch for, and pitches to review.

One feature that is planned but not yet implemented is any ability to have people properly log in, so their session isn’t limited to one cookie.
The intent is to support major SSO providers for identity (Google, MS, Apple).
Another feature which is far from finished is “team play”, in which a group of four or more friends play a private game amongst themselves, seeing each other’s challenges and pitches instead of ones from the general pool.
We could also add features to see review averages of your own pitches, or the collected pitches of a single author.

Fun fact about this game: I wrote it to relax, which means I wrote a lot of it at bedtime, which means the majority of the code was typed in on my phone instead of on a keyboard.

### How to set up

You'll need a web host with PHP and MySql (or MariaDB).
Use `pitchgame tables.sql` to create the database, but don't upload that file to the web host.
Omit the line `CREATE DATABASE pitchgame` if the host has you create the database first by other means.
You will need to set up the database username and password by whatever means your web host supports.

Put your database host, username, and password in `pitch-configure.php`.
Use `.htaccess` or equivalent to forbid downloading `pitch-configure.php` itself.
Also use it to make `pitchgame.php` the default index page in its directory, if that's desirable, i.e. if the game is in its own separate directory.
Also use it to protect `moderator.php` with a password.
(You’ll probably have to set up the user accounts and passwords with some other administrative tool.)
I have provided a sample version of `.htaccess` which does these things.
If you use a web host other than Apache, you will have to devise an alternative.
Set up the other constants in `pitch-configure.php` if you want to use recaptcha, and adjust preference settings there.

Uploading spare03min.js from my [SPARE](https://github.com/paulkienitz/SPARE) repo is recommended but not essential.
Only the moderation page uses it.
Note that SPARE 4 may be incompatible, if that’s out by the time you read this.

Finally, upload the .php, .css, .js, .jpg, and .html-content files from this repo, and test it out.

### Change history:

Original release was November 28, 2021.
Team play was unfinished and not included.
It did not include any means of searching or viewing pitches except to rate ones you had not rated yet, after completing a pitch.

Second version was November 12, 2023.
This made no visible change to the game, but did change some queries used by the moderation page, so as to allow a broader view of recent activity.
It also makes the ideas that pitches responded to visible, whereas they were previously hidden.
(I had some idea that this would make moderation more unbiased.)
Also, tab characters were removed from the source code, and I changed the line endings to consistently use LF only, whereas previously half of them had used CRLF.
