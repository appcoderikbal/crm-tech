/* global techzappCrm */
(function () {
  'use strict';

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
   * Import progress.
   *
   * The browser drives the import in bounded chunks so the operator sees
   * movement, but the server-side worker runs the identical code — closing
   * this tab slows the import down, it does not abandon it.
   */
  var progress = document.getElementById('techzapp-import-progress');
  if (!progress) {
    return;
  }

  var jobId = progress.dataset.job;
  var bar = progress.querySelector('.techzapp-progress__bar');
  var status = document.getElementById('techzapp-import-status');
  var stopped = false;

  function renderCounts(job) {
    ['processed_rows', 'inserted_rows', 'updated_rows', 'skipped_rows', 'failed_rows'].forEach(function (key) {
      var node = document.querySelector('[data-count="' + key + '"]');
      if (node) {
        node.textContent = new Intl.NumberFormat().format(job[key] || 0);
      }
    });
  }

  function step() {
    if (stopped) {
      return;
    }

    var body = new FormData();
    body.append('action', 'techzapp_crm_import_step');
    body.append('nonce', techzappCrm.nonce);
    body.append('job', jobId);

    fetch(techzappCrm.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || !payload.success) {
          throw new Error((payload && payload.data) || 'failed');
        }

        var job = payload.data;
        var percent = Math.min(100, parseInt(job.progress_percent, 10) || 0);

        bar.style.width = percent + '%';
        renderCounts(job);

        if (job.status === 'processing' || job.status === 'queued') {
          window.setTimeout(step, 400);
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
})();
