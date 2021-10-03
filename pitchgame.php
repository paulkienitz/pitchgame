<!DOCTYPE html>
<?php
	require 'pitchdata.php';

	$pagestate = 0;			// 0 = initial view prompting for three words, 1 = prompting for pitch, 2 = reading and rating pitches, ...?
	$con = new Connection();		// defined in pitchdata.php
	$submissionId = null;

	$validationFailed = false;
	$databaseFailed = !$con->isReady();
	$initialSubject = '';
	$initialVerb = '';
	$initialObject = '';
	$idea = '';
	$title = '';
	$pitch = '';
	$signature = '';

	$challenge = null;				// will contain three words and their IDs, structure defined in pitchdata.php
	$pitchesToReview = [];			// array of Pitch structures defined in pitchdata.php

	function myIP()
	{
		return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
	}

	function enc($str)
	{
		return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
	}

	if (!$databaseFailed)
	{
		if (!isset($_COOKIE['pitchgame']) || !$con->getSessionByToken($_COOKIE['pitchgame']))
		{
			$token = $con->makeSession(myIP(), $_SERVER['HTTP_USER_AGENT']);
			if ($token)
				setcookie('pitchgame', $token);
			else
				$databaseFailed = true;
		}
	}
	else
		$con->lastError = "Database connection failed - $con->lastError";

	if (isset($_POST['formtype']) && !$databaseFailed)		// extract form values and validate
	{
		if ($_POST['formtype'] == 'initialwords')			// pagestate 0 form submitted
		{
			$initialSubject = trim($_POST['subject']);
			$initialVerb    = trim($_POST['verb']);
			$initialObject  = trim($_POST['object']);
			$validationFailed = !$initialSubject || !$initialVerb || !$initialObject;
			if (!$validationFailed)
			{
				if ($con->addWords($initialSubject, $initialVerb, $initialObject))
					$challenge = $con->getChallenge();
				$databaseFailed = !$challenge;
				$idea = "$initialSubject $initialVerb $initialObject";		// for display
			}
			$pagestate = $validationFailed ? 0 : 1;
		}
		else if ($_POST['formtype'] == 'pitch')				// pagestate 1 form submitted
		{
			$idea          = trim($_POST['idea']);
			$challenge     = unserialize($_POST['challenge']);
			$title         = trim($_POST['title']);
			$pitch         = trim($_POST['pitch']);
			$signature     = trim($_POST['signature']);
			$validationFailed = !$title || !$pitch;
			if (!$validationFailed)
			{
				$databaseFailed = !$con->addPitch($challenge, $title, $pitch, $signature);
				if (!$databaseFailed)
				{
					$pitchesToReview = $con->getPitchesToReview();
					if ($pitchesToReview && count($pitchesToReview))
						$pagestate = 2;
					else {
						$oldFavoritePitches = $con->getOldFavoritePitches();
						if ($oldFavoritePitches && count($oldFavoritePitches))
							$pagestate = 3;
						else
							$databaseFailed = true;
					}
				}
			}
			else
				$pagestate = 1;
		}
		else if ($_POST['formtype'] == 'review')			// pagestate 2 form submitted
		{
			$pitchesToReview = unserialize($_POST['pitchesToReview']);
			$anyRated = false;
			foreach ($pitchesToReview as $pitcher)
			{
				$rating = trim($_POST['newrating_' . $pitcher->pitchId]);
				if ($rating)
					if ($con->ratePitch($pitcher->pitchId, $rating))
						$anyRated = true;
					else
						$databaseFailed = true;
			}
			if (!$databaseFailed)
				$pagestate = $anyRated ? 5 : 4;
		}
	}
?>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="Author" content="Paul Kienitz">
	<meta name="Description" content="The Movie Pitch Game!  Supply three words, get three words back, and write a movie pitch fitting them.">
	<meta name="KeyWords" content="movies, film, cinema, hollywood, pitch, sell, ideas, game, entertainment, creative, KO Rob, KO Picture Show, kopictureshow.com">
	<title>The Movie Pitch Game!  Pitch a movie from a three word premise</title>

	<style type='text/css'>
		/* this is a rudimentary beginning of the cosmetic styling we need to apply later: */
		body { font-family: serif; }
		.plop { display: none; }
		.validation { font-style: italic; color: #880000; }
		.failure { font-style: italic; color: #CC0000; }
		.exceptional { color: #996666; }
		.title { text-align: center; margin-bottom: 0; }
		.subtitle  { text-align: center; margin-top: 0; }
		.pitch { border: solid 1px silver; padding: 0.6em 1em; }
		.star { color: #DDAA00; }
		.stars .star { font-size: 180%; vertical-align: middle; cursor: pointer; }
		.stars .spam { margin-left: 4em; color: #444444; font-style: italic; font-family: Verdana; font-size: 88%; cursor: pointer; }
		.spam:hover { text-decoration: underline; }
		.spam.marked { font-weight: bold; color: #000000; }
	</style>

	<script type='text/javascript'>
		function showHints()
		{
		}

		function showStars(pitchId, rating)
		{
			for (var r = 1; r <= 4; r++)
			{
				var starId = "star_" + r + "_" + pitchId;
				var star = document.getElementById(starId);
				star.innerHTML = r <= rating ? "&#9733;" : "&#9734;";    // solid star vs hollow star
			}
			var spamId = "spam_" + pitchId;
			var spam = document.getElementById(spamId);
			spam.innerHTML = rating < 0 ? "Marked as spam" : "Mark as spam";
			if (rating < 0)
				spam.classList.add("marked");
			else
				spam.classList.remove("marked");
			var field = document.getElementById("newrating_" + pitchId);
			field.value = rating == 0 ? "" : rating + "";
		}

		function rateWithStars(ev)
		{
			var parts = /star_(\d+)_(\d+)/.exec(this.id);
			var stars = parseInt(parts[1]);
			var pitchId = parseInt(parts[2]);
			showStars(pitchId, stars);
		}

		function rateAsSpamOrClear(ev)
		{
			var parts = /([a-z]+)_(\d+)/.exec(this.id);
			var pitchId = parseInt(parts[2]);
			showStars(pitchId, parts[1] == "spam" ? -1 : 0);
		}

		function init()
		{
			var stars = document.getElementsByClassName('star');
			for (var i = 0; i < stars.length; i++)
				stars[i].addEventListener('click', rateWithStars);
			var stars = document.getElementsByClassName('spam');
			for (var i = 0; i < stars.length; i++)
				stars[i].addEventListener('click', rateAsSpamOrClear);
		}

		window.addEventListener("DOMContentLoaded", init);
	</script>
</head>

<body>
<main>
<h1 class=title>The Movie Pitch Game!</h1>
<h3 class=subtitle>invented by <a href='http://kopictureshow.com'>KO Rob</a></h3>
	
<?php if ($databaseFailed) { ?>

	<h2 class=failure>Error... dang it</h2>
	<p>

	We’re sorry, but the Movie Pitch Game has experienced an internal failure.&ensp;We
	will investigate the problem and try to prevent recurrences.

	</p>
	<div class=exceptional><?=nl2br(enc($con->lastError ?: SqlStatement::$log))?></div>

<?php } else if ($pagestate == 0 || $pagestate == 4 || $pagestate == 5) { ?>

	<p>

	<?php if ($pagestate == 0) { ?>

	This is a game in which you are given a three word story idea, in the form of
	a short sentence with a verb in the middle, like “man bites dog” or “Martians
	invade Belgium”.&ensp;Your job is to pitch a movie based on that idea — that
	is, to describe an unmade film in a paragraph or two, and make it sound like
	something people would want to see.&ensp;For further explanation,
	<a onclick='showHints()'>click here</a>.

	</p><p>

	But before you get your three words, you must supply three words for other
	people to use.&ensp;Please give us a noun, a verb, and another noun:

	<?php } else { ?>

	<i>Thank you for <?=($pagestate == 5 ? "rating" : "reading")?> some of the
	submissions of your fellow players!</i>&ensp;How about playing another round?

	<?php } ?>

	</p>
	<form method="POST">
		<div>
			<input type=hidden name=formtype value="initialwords" />
			<label for=subject>Noun:</label>
			<input type=text id=subject name=subject value='<?=enc($initialSubject)?>' maxlength=50 tabindex=1 />
			<label for=verb>Verb:</label>
			<input type=text id=verb name=verb value='<?=enc($initialVerb)?>' maxlength=50 tabindex=2 />
			<label for=object>Noun:</label>
			<input type=text id=object name=object value='<?=enc($initialObject)?>' maxlength=50 tabindex=3 />
		</div>
	<?php if ($validationFailed) { ?>
		<div class=validation>
			All three values are required if you want to play!
		</div>
	<?php } ?>
		<div>
			<button tabindex=4>Submit Words so I can Make My Pitch</button>
		</div>
	</form>
	<!-- div class=exceptional><?=nl2br(enc(SqlStatement::$log))?></div -->

<?php } else if ($pagestate == 1) { ?>

	<p>

	Thank you.&ensp;Your three word idea, “<?=enc($idea)?>”, will soon be inspiring
	others to come up with creative movie pitches.&ensp;And now it’s your
	turn!&ensp;Your three word story idea is:

	</p>
	<h2>
	<?=enc($challenge->subject)?> <?=enc($challenge->verb)?> <?=enc($challenge->object)?>
	</h2>
	<!-- *** TODO: ADD COMPLAINT OPTION -->

	<form method="POST">
		<div>
			<input type=hidden name=formtype value='pitch' />
			<input type=hidden name=idea value='<?=enc($idea)?>' />
			<input type=hidden name=challenge value='<?=enc(serialize($challenge))?>' />
			<div><label for=title>Movie Title:</label>
			<input type=text id=title name=title value='<?=enc($title)?>' maxlength=100 tabindex=1 /></div>
			<div><label for=pitch>Pitch!:</label>
			<textarea id=pitch name=pitch maxlength=2000 tabindex=2><?=enc($pitch)?></textarea></div>
			<div><label for=signature>Signature (optional):</label>
			<input type=text id=signature name=signature value='<?=enc($signature)?>' maxlength=100 tabindex=3 /></div>
		</div>
	<?php if ($validationFailed) { ?>
		<div class=validation>
			Both Movie Title and Pitch text are required!
		</div>
	<?php } ?>
		<div>
			<button tabindex=4>Submit My Pitch!</button>
		</div>
	</form>
	<!-- div class=exceptional><?=nl2br(enc(SqlStatement::$log))?></div -->

<?php } else if ($pagestate == 2) { ?>

	<p>

	Thank you.&ensp;Here are some other folks’ pitches for you to read.&ensp;Enjoy, and if
	you want, leave a star rating for any or all of the pitches below.

	</p>
	<form method="POST">
		<input type=hidden name=formtype value='review' />
		<input type=hidden name=pitchesToReview value='<?=enc(serialize($pitchesToReview))?>' />
		<button>Submit My Ratings and Play Another Round</button>

	<?php foreach ($pitchesToReview as $pitcher) { ?>
		<blockquote class=pitch>
			<div>
				<i>from the idea “<?=enc($pitcher->subjectNoun)?>
			    <?=enc($pitcher->verb)?> <?=enc($pitcher->objectNoun)?>”:</i>
			</div>
			<h3><?=enc($pitcher->title)?></h3>
			<p style='white-space: pre-wrap'><?=enc($pitcher->pitch)?></p>
			<?php if ($pitcher->signature) echo "<div>&mdash; $pitcher->signature</div>\n"; ?>
			<div class=stars>
				<input type=hidden id='newrating_<?=$pitcher->pitchId?>' name='newrating_<?=$pitcher->pitchId?>' value=''></input>
				Your rating: 
				<a id='star_1_<?=$pitcher->pitchId?>' class=star title='1 star'>&#9734;</a>
				<a id='star_2_<?=$pitcher->pitchId?>' class=star title='2 stars'>&#9734;</a>
				<a id='star_3_<?=$pitcher->pitchId?>' class=star title='3 stars'>&#9734;</a>
				<a id='star_4_<?=$pitcher->pitchId?>' class=star title='4 stars'>&#9734;</a>
				<span id='spam_<?=$pitcher->pitchId?>' class=spam><a>Mark as spam</a></span>
				<span id='clear_<?=$pitcher->pitchId?>' class=spam><a>Undo</a></span>
			</div>
		</blockquote>
	<?php } ?>

		<button>Submit My Ratings and Play Another Round</button>
	</form>
	<!-- div class=exceptional><?=nl2br(enc(SqlStatement::$log))?></div -->

<?php } else if ($pagestate == 3) { ?>

	<p>

	Thank you.&ensp;Unfortunately we have no pitches to show you for rating at this
	time.&ensp;Apparently you have been very diligent about rating everyone else’s
	pitches.&ensp;Instead, here are a few of your old favorites.

	</p>
	<p>

	<form method="POST">
		<input type=hidden name=formtype value='favorites' />
		<button>Play Another Round</button>

	<?php foreach ($oldFavoritePitches as $pitcher) { ?>
		<blockquote class=pitch>
			<div>
				<i>from the idea “<?=enc($pitcher->subjectNoun)?>
			    <?=enc($pitcher->verb)?> <?=enc($pitcher->objectNoun)?>”:</i>
			</div>
			<h3>
				<?=enc($pitcher->title)?>
				<span class=star title='<?=$pitcher->yourRating?> stars'><?=str_repeat('&#9733;', $pitcher->yourRating)?></span>
			</h3>
			<p style='white-space: pre-wrap'><?=enc($pitcher->pitch)?></p>
			<?php if ($pitcher->signature) echo "<div>&mdash; $pitcher->signature</div>\n"; ?>
		</blockquote>
	<?php } ?>

		<button>Play Another Round</button>

	</p>
	<!-- div class=exceptional><?=nl2br(enc(SqlStatement::$log))?></div -->

<?php } ?>
</main>

<aside class=plop id=howtoplay>
<h2>How to play the Movie Pitch Game:</h2>
<p>

This is a game of humor and creativity for movie buffs.&ensp;It was invented by
KO Rob of <a href='http://kopictureshow.com'>The KO Picture Show</a>.&ensp;It
can be played at home without a computer, but I put it on the web so that anyone
can play whether they have friends over or not.

</p><p>

Your first step is to submit three words: a noun, a verb, and another
noun.&ensp;The nouns can be ordinary everyday nouns such as “car” or “pig”,
abstractions like “progress” or “mediocrity”, proper names like “Arkansas” or
“Julius Caesar”, or short noun phrases like “ham and pineapple pizza” or
“the Royal Regiment of Scotland”.&ensp;The verb should usually be in present
tense, like “invents” or “punches”, but past tense verbs such as “buried” or
“indocrinated” are acceptable.&ensp;And short verb phrases such as “cheats on”
or “flees in terror from” are okay too.

</p><p>

The idea is that these can be read as a short sentence, like “man
bites dog”, or “gangster meets vampire”, or “Tutankhamen misunderstands
anarcho-syndicalism”.&ensp;Your three words are stored for later use, and then
you get three different words from the stored list. But you don’t get a set of
three that a person submitted together... you get three words that were
submitted on different days by different people, which as a result may not work
together at all.&ensp;But because they follow the verb-noun-verb pattern, they
should still be readable as a short sentence.&ensp;For instance, if three
previous players had submitted the three sets of words above, you might be
presented with “gangster misunderstands dog” or “Tutankhamen bites vampire”.

</p><p>

Your job now is simple, but not easy: <i>Pitch!</i>&ensp;Write a title and
short description of a movie to be made based on the three-word idea you’ve
just been given.&ensp;Make it sound as fun and interesting as you can.&ensp;The
purpose of a pitch is to convince people to want to make and see your
movie.&ensp;For example, if given “Tutankhamen bites vampire”, you might write:

</p>
<blockquote class=pitch>
<h3>PHARAOH OF EVIL</h3>
<p>

Vampires are afoot, and ex-cop Dirk Jones (Liam Neeson) traces the infestation
to Egypt.&ensp;There he teams up with archaeologist Bella Star (Selena Gomez),
and they battle their way to the nest where the vampire plague began: the
underground palace of the undead Boy King!&ensp;Can they stop the ancient child
Pharaoh from spreading his evil curse across all lands, bite by bite?&ensp;Let
breakout director Duncan Jones take you on a stylish and thrill-packed trip
from the towers of New York to the tombs of ancient Egypt.

</p>
</blockquote>
<p>

Once you’ve written a pitch, you can then read some pitches written by others,
and rate them with one to four stars.&ensp;Vote for the ones you find most
creative and entertaining, so more people can enjoy them.

</p><p>

One unavoidable awkwardness of this approach is that sometimes a singular noun
gets matched with a plural verb, or vice versa, such as “Martians seeks horse”
or “Arnold Schwarzenegger invade the Parthenon”.&ensp;Just be understanding and
adjust the verb in your head, and <i>make that pitch!</i>

</p>
<hr/>
<p>

In the basic version of the game, you just put in your three words, get back
three different words, and pitch.&ensp;In the team version, you get friends
together to form a group of four.&ensp;To do this, ask for a group code, then
give the code to the three others you’ll play the game with.&ensp;Each of you
four put in your three words, then each of you gets back one word from each of
your three friends.&ensp;For instance, four friends might make the following
inputs:

</p><p>

Alice: “tomato stains carpet”
Bob:   “monster tastes coffee”
Chuck: “Lao-Tzu predicted Facebook”
Diane: “King Henry VIII ruins water polo”

</p><p>

With these submissions, the game might shuffle the players to give them the
following movie ideas:

</p><p>

Alice: “Lao-Tzu tastes water polo”
Bob:   “King Henry VIII predicted carpet”
Chuck: “tomato ruins coffee”
Diane: “monster stains Facebook”

</p><p>

They each then write a pitch based on the premise they’ve been given.&ensp;Some
combinations will be easier to make sense of than others, obviously — in this
case, Chuck’s job looks pretty straightforward until he tries to make it sound
interesting, but Alice has got a real challenge to make any sense at all.

</p><p>

Once done, of course, they read and enjoy each other’s pitches, and can rate
them if they wish to.&ensp;If the players want to pick a winner from among the
four, that’s up to them.&ensp;The game doesn’t have to be competitive unless
the players want it to be.&ensp;Then if they desire, they can start another
round.&ensp;Play as long and as often as you like.

</aside>

</body>
</html>