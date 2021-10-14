function polyfill_closest()
{			// this is taken from MDN:
	if (!Element.prototype.matches)
		Element.prototype.matches = Element.prototype.msMatchesSelector || Element.prototype.webkitMatchesSelector;
	if (!Element.prototype.closest)
		Element.prototype.closest = function(s) {
			var el = this;
			do {
				if (Element.prototype.matches.call(el, s))
					return el;
				el = el.parentElement || el.parentNode;
			} while (el !== null && el.nodeType === 1);
			return null;
		};
}

function showHints(ev)
{
	var plopup = document.getElementById('howtoplay');
	plopup.style.display = 'block';
	ev.preventDefault();
	ev.stopPropagation();
	return false;
}

function showLog(ev)
{
	var log = document.getElementById('theLog');
	log.style.display = 'block';
	ev.preventDefault();
	ev.stopPropagation();
	return false;
}

function showSessionSummary(ev)
{
	var sessionId = this.id.substring(8);
	var url = "pitchgame_admin.php?sessionId=" + sessionId;
	var plopup = document.getElementById('sessionStats');
	SPARE.replaceContent("userSummarySpot", url, "userSummary", null, plopup, function (plopup) {
		plopup.style.display = 'block';
	});
	ev.preventDefault();
	ev.stopPropagation();
	return false;
}

function closer()
{
	this.closest('.plop').style.display = 'none';
}

function beModerate(ev)
{
	var formtype = document.getElementById('formtype');
	var form = document.getElementById('pitchform');
	if (form && formtype)
	{
		formtype.value = 'moderate';
		form.submit();
	}
	ev.preventDefault();
	ev.stopPropagation();
	return false;
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
	polyfill_closest();
	var showhints = document.getElementById('showhints');
	if (showhints)
		showhints.addEventListener('click', showHints);
	var showlog = document.getElementById('showLog');
	if (showlog)
		showlog.addEventListener('click', showLog);
	var closers = document.getElementsByClassName('closer');
	for (var i = 0; i < closers.length; i++)
		closers[i].addEventListener('click', closer);
	var backers = document.getElementsByClassName('backer');
	for (var i = 0; i < backers.length; i++)
		backers[i].addEventListener('click', closer);
	var moderato = document.getElementById('moderato');
	if (moderato)
		moderato.addEventListener('click', beModerate);
	var stars = document.getElementsByClassName('star');
	for (var i = 0; i < stars.length; i++)
		stars[i].addEventListener('click', rateWithStars);
	var stars = document.getElementsByClassName('spam');
	for (var i = 0; i < stars.length; i++)
		stars[i].addEventListener('click', rateAsSpamOrClear);
	var sessions = document.getElementsByClassName('slinky');
	for (var i = 0; i < sessions.length; i++)
		sessions[i].addEventListener('click', showSessionSummary);
}

window.addEventListener("DOMContentLoaded", init);
