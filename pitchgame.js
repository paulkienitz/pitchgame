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

function randomize(ev)   // argh thus is useless...
{
	var form = document.forms[0];
	var seedy = form ? form['seed'] : undefined;
	if (seedy)
		seedy.value = '';
	if (form)
		form.submit();
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
		var lsi = document.getElementById('lastSessionId');
		lsi.value = sessionId + '';
		attach('#historicize', beHistorical);
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

function beHistorical(ev)
{
	var formtype = document.getElementById('formtype');
	var form = document.forms[0];
	if (form && formtype)
	{
		formtype.value = 'history';
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

function attach(selector, handler, event)
{
	var finding = document.querySelectorAll(selector);
	for (var i = 0; i < finding.length; i++)
		finding[i].addEventListener(event || 'click', handler);
}

function init()
{
	polyfill_closest();
	attach('#showLog', showLog);
	attach('#randomize', randomize);
	attach('#showhints', showHints);
	attach('.closer, .backer', closer);
	attach('#moderato', beModerate);
	attach('.star', rateWithStars);
	attach('.spam', rateAsSpamOrClear);
	attach('.slinky', showSessionSummary);
    attach('#historicize', beHistorical);       // normally used only in a SPARE popup
}

window.addEventListener("DOMContentLoaded", init);
