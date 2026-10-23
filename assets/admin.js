/* global techzappCrm */
(function () {
  'use strict';

  var number = new Intl.NumberFormat();

  /**
   * Confirm prompts for destructive links.
   *
   * Applied by class rather than inline onclick so the markup stays free of
   * JavaScript and the strings can be translated server-side.
   */
  document.addEventListener('click', function (event) {
    var target = event.target.closest('.techzapp-confirm');
    if (!target) {
      return;
    }

    var message = target.dataset.confirm || techzappCrm.i18n.confirmDelete;
    if (!window.confirm(message)) {
      event.preventDefault();
    }
  });

  /**
   * Posts one admin-ajax step.
   *
   * Rejects on both a transport failure and an unsuccessful payload, so every
   * caller has a single error path.
   */
  function postStep(action, fields) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', techzappCrm.nonce);

    Object.keys(fields).forEach(function (key) {
      body.append(key, fields[key]);
    });

    return fetch(techzappCrm.ajaxUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin'
    })
      // Read as text, not json(). A PHP fatal, a memory limit or a firewall
      // returns an empty body or an HTML error page, and response.json()
      // reports only "Unexpected end of JSON input" — which says nothing
      // about what actually went wrong.
      .then(function (response) {
        return response.text().then(function (text) {
          return { status: response.status, text: text };
        });
      })
      .then(function (result) {
        var text = (result.text || '').trim();

        if (text === '') {
          throw new Error(
            techzappCrm.i18n.emptyResponse.replace('%d', result.status) +
            (result.status === 200
              ? ' ' + techzappCrm.i18n.emptyResponseHint
              : '')
          );
        }

        var payload;
        try {
          payload = JSON.parse(text);
        } catch (e) {
          // Show what actually came back, stripped of markup, so a PHP
          // error or a WAF block is recognisable at a glance.
          var snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
          throw new Error(techzappCrm.i18n.badResponse.replace('%d', result.status) + ' ' + snippet);
        }

        if (!payload || !payload.success) {
          throw new Error((payload && payload.data) || 'failed');
        }

        return payload.data;
      });
  }

  function setCount(root, key, value) {
    var node = root.querySelector('[data-count="' + key + '"]');
    if (node) {
      node.textContent = number.format(value || 0);
    }
  }

  /**
   * Import progress.
   *
   * The browser drives the import in bounded chunks so the operator sees
   * movement. With the scheduler enabled the server-side worker runs the
   * identical code, so closing this tab slows the import rather than
   * abandoning it; in manual mode this loop is the only thing driving it.
   */
  function initImport() {
    var progress = document.getElementById('techzapp-import-progress');
    if (!progress) {
      return;
    }

    var jobId = progress.dataset.job;
    var bar = progress.querySelector('.techzapp-progress__bar');
    var status = document.getElementById('techzapp-import-status');
    var stopped = false;

    function step() {
      if (stopped) {
        return;
      }

      postStep('techzapp_crm_import_step', { job: jobId })
        .then(function (job) {
          var percent = Math.min(100, parseInt(job.progress_percent, 10) || 0);
          bar.style.width = percent + '%';

          ['processed_rows', 'inserted_rows', 'updated_rows', 'skipped_rows', 'failed_rows']
            .forEach(function (key) { setCount(document, key, job[key]); });

          if (job.status === 'processing' || job.status === 'queued') {
            // Deliberately unhurried: a tight poll looks like an attack to
            // the rate limiters that sit in front of shared hosting.
            window.setTimeout(step, 1500);
            return;
          }

          bar.classList.add('is-done');
          stopped = true;

          if (status) {
            status.textContent = techzappCrm.i18n.importFinished;
          }
          window.location.reload();
        })
        .catch(function () {
          stopped = true;
          if (status) {
            status.textContent = techzappCrm.i18n.importFailed;
          }
        });
    }

    step();
  }

  /**
   * Campaign sending, driven from this tab.
   *
   * Each call sends one bounded batch and returns where it got to, so progress
   * survives a closed tab: reopening the campaign and pressing start again
   * picks up from the next unsent recipient rather than starting over.
   */
  function initSend() {
    var panel = document.getElementById('techzapp-send-progress');
    if (!panel) {
      return;
    }

    var campaignId = panel.dataset.campaign;
    var bar = panel.querySelector('.techzapp-progress__bar');
    var status = panel.querySelector('[data-role="status"]');
    var startButton = document.getElementById('techzapp-send-start');
    var stopButton = document.getElementById('techzapp-send-stop');
    var running = false;
    var stopped = false;

    function say(message) {
      if (status) {
        status.textContent = message;
      }
    }

    function setRunning(isRunning) {
      running = isRunning;

      if (startButton) {
        startButton.disabled = isRunning;
      }
      if (stopButton) {
        stopButton.hidden = !isRunning;
      }
      panel.classList.toggle('is-running', isRunning);
    }

    var log = panel.querySelector('[data-role="log"]');
    var seen = Object.create(null);

    /**
     * Appends only recipients this tab has not already shown.
     *
     * The endpoint returns a rolling window rather than a cursor, so entries
     * repeat between calls; keying by recipient id keeps the log honest
     * instead of printing the same address on every poll.
     */
    function appendLog(entries) {
      if (!log || !entries || !entries.length) {
        return;
      }

      // Oldest first, so the newest ends up at the bottom.
      entries.slice().reverse().forEach(function (entry) {
        if (seen[entry.id]) {
          return;
        }
        seen[entry.id] = true;

        var line = document.createElement('div');
        line.className = 'techzapp-log__line is-' + entry.status;

        var status = document.createElement('span');
        status.className = 'techzapp-log__status';
        status.textContent = entry.status;

        var email = document.createElement('span');
        email.className = 'techzapp-log__email';
        email.textContent = entry.email;

        line.appendChild(status);
        line.appendChild(email);

        if (entry.detail) {
          var detail = document.createElement('span');
          detail.className = 'techzapp-log__detail';
          detail.textContent = entry.detail;
          line.appendChild(detail);
        }

        if (entry.attempts > 1) {
          var attempts = document.createElement('span');
          attempts.className = 'techzapp-log__detail';
          attempts.textContent = '(attempt ' + entry.attempts + ')';
          line.appendChild(attempts);
        }

        log.appendChild(line);
      });

      // Only follow the tail if the operator has not scrolled up to read.
      var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
      if (atBottom) {
        log.scrollTop = log.scrollHeight;
      }

      log.hidden = false;
    }

    function render(data) {
      var percent = Math.min(100, parseInt(data.percent, 10) || 0);
      bar.style.width = percent + '%';

      ['total', 'sent', 'pending', 'failed', 'skipped', 'unconfirmed'].forEach(function (key) {
        setCount(panel, key, data[key]);
      });

      appendLog(data.log);
    }

    function step() {
      if (stopped) {
        return;
      }

      postStep('techzapp_crm_send_step', { campaign: campaignId })
        .then(function (data) {
          render(data);

          if (data.done) {
            setRunning(false);
            bar.classList.add('is-done');
            say(data.note || techzappCrm.i18n.sendFinished);

            // Let the report pick up the final figures.
            window.setTimeout(function () { window.location.reload(); }, 1500);
            return;
          }

          // A note with nothing sent means a cap was hit — a send rate or a
          // daily limit. Backing off is correct; hammering would not send a
          // single extra message.
          var wait = data.note ? 10000 : 2000;
          say(data.note || techzappCrm.i18n.sendRunning);

          window.setTimeout(step, wait);
        })
        .catch(function (error) {
          stopped = true;
          setRunning(false);
          say((error && error.message) || techzappCrm.i18n.sendFailed);
        });
    }

    function start() {
      if (running) {
        return;
      }

      stopped = false;
      setRunning(true);
      say(techzappCrm.i18n.sendStarting);
      step();
    }

    if (startButton) {
      startButton.addEventListener('click', function (event) {
        event.preventDefault();

        var message = startButton.dataset.confirm;
        if (message && !window.confirm(message)) {
          return;
        }

        start();
      });
    }

    if (stopButton) {
      stopButton.addEventListener('click', function (event) {
        event.preventDefault();

        if (!window.confirm(techzappCrm.i18n.confirmStop)) {
          return;
        }

        // Only stops this loop. Messages already handed to SES are gone, and
        // the campaign stays mid-send so it can be resumed.
        stopped = true;
        setRunning(false);
        say(techzappCrm.i18n.sendStopped);
      });
    }

    if (panel.dataset.autostart === '1') {
      start();
    }
  }

  initImport();
  initSend();
})();
