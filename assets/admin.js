(function($){
	let running = false;
	let jobId = null;
	let statusTimer = null;
	let stepTimer = null;
	let historyTimer = null;
	let idleAttachTimer = null;
	let terminalAlertShown = false;
	let elapsedTimer = null;
	let jobStartedAtMs = null;
	let jobElapsedFinalSecs = null;
	let stepFailCount = 0;
	let cancelling = false;
	let confirmTimer = null;
	let esiOverall = null; // 'OK'|'Degraded'|'Recovering'|'Down'|null
	let observeOnly = false; // true = status-only, do not call ett_job_step (used for cron-driven jobs)
	let runBtnLabel = null;
	let genBtnLabel = null;
    let genBtnFlashTimer = null;
	let runGen = 0; // increments whenever we stop/cancel/start to invalidate in-flight AJAX responses
    let statusInFlight = false;
    let stepInFlight = false;

	function nowMs(){ return Date.now(); }

	function setHeartbeatVisible(show){
		$('#ett-heartbeat').toggle(!!show);
		if (!show) $('#ett-stalled').hide();
	}

	function setHeartbeat(tsMysql){
		const el = $('#ett-heartbeat');
		const dot = el.find('.ett-dot');
		const txt = el.find('.ett-hb-text');

		if (!tsMysql){
			dot.removeClass('ok bad');
			txt.text('No heartbeat');
			return;
		}

		const hb = new Date(tsMysql.replace(' ', 'T'));
		const delta = nowMs() - hb.getTime();

		if (isNaN(delta)){
			dot.removeClass('ok bad');
			txt.text('Heartbeat unknown');
			return;
		}

		if (delta <= 15000){
			dot.removeClass('bad').addClass('ok');
			txt.text('Heartbeat OK');
			$('#ett-stalled').hide();
			return;
		}

		dot.removeClass('ok').addClass('bad');
		txt.text('Heartbeat stale');
		$('#ett-stalled').show();
	}

	function parseWpMysql(ts){
		if (!ts) return null;
		const d = new Date(ts.replace(' ', 'T'));
		return isNaN(d.getTime()) ? null : d;
	}

	function secondsSince(ts){
		const d = parseWpMysql(ts);
		if (!d) return null;
		return Math.floor((Date.now() - d.getTime()) / 1000);
	}

	function formatHMS(totalSeconds){
		if (totalSeconds === null || totalSeconds < 0) return '00:00:00';

		const hours = Math.floor(totalSeconds / 3600);
		const minutes = Math.floor((totalSeconds % 3600) / 60);
		const seconds = totalSeconds % 60;
		const pad = (n) => n.toString().padStart(2, '0');

		return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
	}

	function startElapsedTicker(){
		if (elapsedTimer) clearInterval(elapsedTimer);

		const tick = () => {
			if (!jobStartedAtMs) return;
			const secs = Math.floor((Date.now() - jobStartedAtMs) / 1000);
			$('#ett-kpi-elapsed').text(formatHMS(secs));
		};

		tick();
		elapsedTimer = setInterval(tick, 1000);
	}

	function stopElapsedTicker(){
		if (elapsedTimer) clearInterval(elapsedTimer);
		elapsedTimer = null;

		if (jobStartedAtMs){
			jobElapsedFinalSecs = Math.floor((Date.now() - jobStartedAtMs) / 1000);
		}

		jobStartedAtMs = null;
	}

	function startConfirmTicker(){
		if (confirmTimer) clearInterval(confirmTimer);

        const lastIso = ETT_ADMIN.last_price_run_completed_at_iso || '';
        const d = lastIso ? new Date(lastIso) : parseWpMysql(ETT_ADMIN.last_price_run_completed_at);
		if (!d) return;

		const tick = () => {
			const secs = Math.floor((Date.now() - d.getTime()) / 1000);
			if (secs < 0) return;

			const elapsed = formatHMS(secs);
			const esiPrefix = (esiOverall === 'Degraded' || esiOverall === 'Recovering')
				? `ESI is currently ${esiOverall}. Running prices now may fail or be slow.\n\n`
				: '';

			$('#ett-run-confirm-text').text(
				`${esiPrefix}A price run completed ${elapsed} ago. Running again may increase ESI load. Are you sure you want to run prices now?`
			);
		};

		tick();
		confirmTimer = setInterval(tick, 1000);
	}

    function refreshDbStatus(){
      return $.post(ETT_ADMIN.ajax_url, {
        action: 'ett_db_status_ajax',
        _ajax_nonce: ETT_ADMIN.nonce
      }).done(function(res){
        if(!res || !res.success) return;
    
        const db = res.data.db_test;     // null or {ok,message}
        const schemaOk = !!res.data.schema_ok;
    
        const $dbEl = $('#ett-db-status-text');
        const $schemaEl = $('#ett-db-schema-text');
    
        if(!db){
          $dbEl.removeClass('ett-ok ett-bad').addClass('ett-bad').text('Not configured.');
        } else {
          $dbEl.removeClass('ett-ok ett-bad')
               .addClass(db.ok ? 'ett-ok' : 'ett-bad')
               .text(db.message || (db.ok ? 'OK' : 'Error'));
        }
    
        $schemaEl.removeClass('ett-ok ett-bad')
                 .addClass(schemaOk ? 'ett-ok' : 'ett-bad')
                 .text(schemaOk ? 'Ready' : 'Not ready');
      });
    }

	function showRunConfirm(text, onYes){
		$('#ett-run-confirm-text').text(text);
		$('#ett-run-confirm').show();

		const yes = $('#ett-run-confirm-yes');
		const no = $('#ett-run-confirm-no');

		const cleanup = () => {
			$('#ett-run-confirm').hide();
			yes.off('click._ettconfirm');
			no.off('click._ettconfirm');
			if (confirmTimer){
				clearInterval(confirmTimer);
				confirmTimer = null;
			}
		};

		yes.on('click._ettconfirm', function(){
			cleanup();
			onYes();
		});

		no.on('click._ettconfirm', function(){
			cleanup();
		});
	}

	function fmtInt(v){
		if (v === null || v === undefined || v === '' || isNaN(Number(v))) return '—';
		return Number(v).toLocaleString();
	}

	function hubLabel(hubKey){
		if (!hubKey) return '—';

		const map = {
			jita: 'Jita',
			amarr: 'Amarr',
			rens: 'Rens',
			dodixie: 'Dodixie',
			hek: 'Hek'
		};

		return map[hubKey] || (hubKey.charAt(0).toUpperCase() + hubKey.slice(1));
	}

	function renderProgress(progress){
		progress = progress || {};

		const phase = (progress.phase || '').toLowerCase();
		const jobType = (progress.job_type || '').toLowerCase();

		let phaseText = 'Idle.';
		if (phase === 'queued') phaseText = 'Queued';
		else if (phase === 'init') phaseText = (jobType === 'prices') ? 'Starting price run…' : 'Starting…';
		else if (phase === 'hub') phaseText = 'Fetching market data…';
		else if (phase === 'done') phaseText = 'Completed successfully.';
		else if (phase === 'cancelled') phaseText = 'Cancelled.';
		else if (phase === 'error') phaseText = 'Error.';
		else if (phase) phaseText = phase;

		const phaseEl = $('#ett-job-phase');
		phaseEl.removeClass('ett-phase-ok ett-phase-bad ett-phase-warn');
		phaseEl.text(phaseText);

		if (phase === 'done'){
			if (progress.warning_msg || progress.rate_limited_seen) phaseEl.addClass('ett-phase-warn');
			else phaseEl.addClass('ett-phase-ok');
		} else if (phase === 'error'){
			phaseEl.addClass('ett-phase-bad');
		} else if (phase === 'cancelled'){
			phaseEl.addClass('ett-phase-warn');
		}

		let hubTxt = hubLabel(progress.current_hub);
		const src = (progress.source || 'primary').toLowerCase();

		if (src === 'secondary'){
			const lbl = progress.secondary_label || '';
			hubTxt += lbl ? ` (Secondary - ${lbl})` : ' (Secondary)';
		} else if (src === 'tertiary'){
			const lbl = progress.tertiary_label || '';
			hubTxt += lbl ? ` (Tertiary - ${lbl})` : ' (Tertiary)';
		}

		$('#ett-kpi-hub').text(hubTxt);

		let pageVal = progress.page;
		if (phase === 'done' && progress.details && progress.details.page) pageVal = progress.details.page;

		$('#ett-kpi-page').text(fmtInt(pageVal));
		$('#ett-kpi-orders').text(fmtInt(progress.orders_seen));
		$('#ett-kpi-matched').text(fmtInt(progress.matched_orders));
		$('#ett-kpi-written').text(fmtInt(progress.rows_written));

		let msg = progress.last_msg || '—';
		msg = msg.replace(/^Hub\s+([a-z0-9_]+)\s+Primary:/i, (m, key) => `Hub ${hubLabel(key)}:`);
		msg = msg.replace(/^Hub\s+([a-z0-9_]+)\s+Secondary\s*\(([^)]+)\):/i, (m, key, sec) => `Hub ${hubLabel(key)} (Secondary - ${sec}):`);
		msg = msg.replace(/^Hub\s+([a-z0-9_]+)\s+Tertiary\s*\(([^)]+)\):/i, (m, key, ter) => `Hub ${hubLabel(key)} (Tertiary - ${ter}):`);
		msg = msg.replace(/^Hub\s+([a-z0-9_]+):/i, (m, key) => `Hub ${hubLabel(key)}:`);

		$('#ett-job-msg').text(msg);

		const warn = progress.warning_msg || '';
		if (warn) $('#ett-job-warn').text(warn).show();
		else $('#ett-job-warn').hide().text('');

		const out = Object.assign({}, progress);
		if (out.driver === 'browser') out.driver = 'Manual';
        else if (out.driver === 'cron') out.driver = 'Scheduled';
        
		let secs = null;

		if (jobStartedAtMs) secs = Math.floor((Date.now() - jobStartedAtMs) / 1000);
		else if (jobElapsedFinalSecs !== null) secs = jobElapsedFinalSecs;

		if (secs !== null){
			out.elapsed_seconds = secs;
			out.elapsed_hms = formatHMS(secs);
		}

		$('#ett-progress-json').text(JSON.stringify(out, null, 2));
	}

	async function ajax(method, data){
		return $.ajax({
			url: ETT_ADMIN.ajax_url,
			method: method,
			dataType: 'json',
			data: data,
			cache: false
		});
	}

	function setEsiStatus(colorClass, text, note){
		const el = $('#ett-esi');
		const dot = el.find('.ett-dot');
		const txt = $('#ett-esi-text');

		dot.removeClass('ok warn bad');
		if (colorClass) dot.addClass(colorClass);

		txt.text(text || 'ESI: Unknown');
		txt.attr('title', note ? String(note) : '');
	}

	async function refreshEsiStatus(){
		try {
			const r = await ajax('GET', {
				action: 'ett_esi_status',
				_ajax_nonce: ETT_ADMIN.nonce
			});

			if (!r || !r.success || !r.data){
				esiOverall = 'Down';
				setEsiStatus('bad', 'ESI: Down', (r && r.data && r.data.note) ? r.data.note : '');
			} else {
				esiOverall = r.data.overall || 'Down';
				const note = r.data.note || '';

				if (r.data.color === 'ok') setEsiStatus('ok', `ESI: ${esiOverall}`, note);
				else if (r.data.color === 'warn') setEsiStatus('warn', `ESI: ${esiOverall}`, note);
				else setEsiStatus('bad', `ESI: ${esiOverall}`, note);
			}
		} catch (e){
			esiOverall = 'Down';
			setEsiStatus('bad', 'ESI: Down', (e && e.message) ? e.message : 'Request failed');
		}

		const runBtn = $('#ett-btn-run');
		if (runBtnLabel === null) runBtnLabel = runBtn.text();

		if (esiOverall === 'Down'){
			runBtn.prop('disabled', true);
			runBtn.text('ESI DOWN');
			return;
		}

		runBtn.prop('disabled', !!running);
		runBtn.text(runBtnLabel);
	}

    function escapeHtml(s){
    	return String(s || '')
    		.replaceAll('&', '&amp;')
    		.replaceAll('<', '&lt;')
    		.replaceAll('>', '&gt;')
    		.replaceAll('"', '&quot;')
    		.replaceAll("'", '&#039;');
    }

	async function refreshRunHistory(){
		const host = $('#ett-run-history');
		if (!host.length) return;

		try {
			const r = await ajax('GET', {
				action: 'ett_job_history',
				limit: 25,
				_ajax_nonce: ETT_ADMIN.nonce
			});

			if (!r || !r.success) return;

			const rows = (r.data && r.data.rows) ? r.data.rows : [];
			const tz = ETT_ADMIN.wp_timezone_string || 'UTC';

			if (!rows.length){
				host.html('<p class="description">No price runs found yet.</p>');
				return;
			}

			let html = '';
			html += '<table class="widefat striped" style="margin-top:8px;">';
			html += '<thead><tr>';
			html += '<th>Started</th><th>Finished</th><th>Status</th><th>Driver</th><th>Last message</th><th>Error</th>';
			html += '</tr></thead><tbody>';

			for (const row of rows){
				html += '<tr>';
				html += `<td>${escapeHtml(row.started_at)} (${escapeHtml(tz)})</td>`;
				html += `<td>${escapeHtml(row.finished_at)} (${escapeHtml(tz)})</td>`;
				html += `<td>${escapeHtml(row.status)}</td>`;
				let driverLabel = row.driver;
                if (driverLabel === 'browser') driverLabel = 'Manual';
                else if (driverLabel === 'cron') driverLabel = 'Scheduled';
                
                html += `<td>${escapeHtml(driverLabel)}</td>`;
				html += `<td>${escapeHtml(row.last_msg)}</td>`;
				html += `<td>${escapeHtml(row.last_error)}</td>`;
				html += '</tr>';
			}

			html += '</tbody></table>';
			host.html(html);
		} catch (e){
			// ignore
		}
	}

    async function refreshLastPriceRun(){
      try {
        const r = await ajax('GET', {
          action: 'ett_last_price_run_ajax',
          _ajax_nonce: ETT_ADMIN.nonce
        });
    
        if (r && r.success && r.data && typeof r.data.last_txt === 'string'){
          $('#ett-last-price-run').text(r.data.last_txt);
          if (typeof r.data.last === 'string'){
            ETT_ADMIN.last_price_run_completed_at = r.data.last;
          }
        }
      } catch (e){
        // ignore
      }
    }

    async function refreshNextRun(){
      try {
        const r = await ajax('GET', {
          action: 'ett_next_run_ajax',
          _ajax_nonce: ETT_ADMIN.nonce
        });
        if (r && r.success && r.data && typeof r.data.next_txt === 'string'){
          $('#ett-next-run').text(r.data.next_txt);
        }
      } catch (e){
        // ignore
      }
    }

	function startHistoryAutoRefresh(){
		if (historyTimer) return;
		refreshRunHistory();
		historyTimer = setInterval(refreshRunHistory, 5000);
	}

	function stopHistoryAutoRefresh(){
		if (historyTimer) clearInterval(historyTimer);
		historyTimer = null;
	}

	function beginMonitoring(){
	    stepFailCount = 0;
            if (statusTimer) clearInterval(statusTimer);
            statusTimer = null;
            
            // Only poll status when we're observe-only (cron-driven).
            // Manual runs already get progress from ett_job_step and polling can throttle.
            if (observeOnly){
                statusTimer = setInterval(async () => {
                    if (!running || !jobId) return;
                    if (cancelling) return;
                    if (statusInFlight) return;
            
                    const myGen = runGen;
                    const myJob = jobId;
            
                    statusInFlight = true;
                    try {
                        const st = await ajax('GET', {
                            action: 'ett_job_status',
                            job_id: myJob,
                            _ajax_nonce: ETT_ADMIN.nonce
                        });
            
                        // Ignore late/stale responses (e.g. after cancel/stop/start)
                        if (myGen !== runGen || !running || !jobId || jobId !== myJob) return;
            
                        if (st && st.success){
                            setHeartbeat(st.data.heartbeat_at);
                            renderProgress(st.data.progress);
            
                            if (['done', 'error', 'cancelled'].includes(st.data.status)){
                                if (st.data.status === 'error' && !terminalAlertShown){
                                    terminalAlertShown = true;
                                    const msg =
                                        st.data?.progress?.error?.message ||
                                        st.data?.last_error ||
                                        st.data?.progress?.last_msg ||
                                        'Job error.';
                                    alert(msg);
                                }
            
                                if (st.data.status === 'done' && st.data.progress && st.data.progress.job_type === 'prices'){
                                    const completed = st.data.finished_at || ETT_ADMIN.last_price_run_completed_at;
                                    const tz = ETT_ADMIN.wp_timezone_string || 'UTC';
            
                                    if (completed){
                                        ETT_ADMIN.last_price_run_completed_at = completed;
                                        $('#ett-last-price-run').text(`${completed} (${tz})`);
                                    }
                                }
            
                                if (st.data.status === 'done' && st.data.progress && st.data.progress.job_type === 'typeids'){
                                  const generated = st.data.progress?.details?.generated_typeids;
                                  if (generated !== null && generated !== undefined && !isNaN(Number(generated))){
                                    $('#ett-current-typeids').text(Number(generated).toLocaleString());
                                  }
                                  flashGeneratedTypeids(generated);
                                }
                                stopJob();
                            }
                        }
                    } catch (e){
                        // keep running; heartbeat UI will warn if it stalls
                    } finally {
                        statusInFlight = false;
                    }
                }, 2000);
            }

            async function doStepTick(){
                if (!running || !jobId) return;
                if (stepInFlight) return;
            
                const myGen = runGen;
                const myJob = jobId;
            
                stepInFlight = true;
                try {
                    const r = await ajax('POST', {
                        action: 'ett_job_step',
                        job_id: myJob,
                        _ajax_nonce: ETT_ADMIN.nonce
                    });
            
                    // Ignore late/stale responses (e.g. after cancel/stop/start)
                    if (myGen !== runGen || !running || !jobId || jobId !== myJob) return;
            
                    if (r && r.success){
                        stepFailCount = 0;
                        $('#ett-job-warn').hide().text('');
                        renderProgress(r.data.progress);
                        
                        if (r.data && r.data.heartbeat_at){
                            setHeartbeat(r.data.heartbeat_at);
                        }

                        if (['done', 'error', 'cancelled'].includes(r.data.status)){
                          if (r.data.status === 'error' && !terminalAlertShown){
                            terminalAlertShown = true;
                            const msg =
                              r.data?.progress?.error?.message ||
                              r.data?.last_error ||
                              r.data?.progress?.last_msg ||
                              'Job error.';
                            alert(msg);
                          }
                        
                          if (r.data.status === 'done' && r.data.progress && r.data.progress.job_type === 'typeids'){
                            const generated = r.data.progress?.details?.generated_typeids;
                            if (generated !== null && generated !== undefined && !isNaN(Number(generated))){
                              $('#ett-current-typeids').text(Number(generated).toLocaleString());
                            }
                            flashGeneratedTypeids(generated);
                          }
                        
                          stopJob();
                        }

                        return;
                    }
            
                    stopJob();
                    if (!terminalAlertShown){
                        terminalAlertShown = true;
                        alert((r && r.data && r.data.message) ? r.data.message : 'Job error.');
                    }
                } catch (e){
                    // If we were invalidated while request was in-flight, ignore the error too
                    if (myGen !== runGen || !running || !jobId || jobId !== myJob) return;
            
                    stepFailCount++;
            
                    const detail = (e.responseJSON?.data?.message || e.message || 'unknown error');
                    $('#ett-job-warn').text(`Connection hiccup while stepping job (attempt ${stepFailCount}). Retrying… ${detail}`).show();
            
                    if (stepFailCount >= 5){
                        stopJob();
                        if (!terminalAlertShown){
                            terminalAlertShown = true;
                            alert('Job failed after repeated step errors: ' + detail);
                        }
                    }
                } finally {
                    stepInFlight = false;
                }
            }
            
            if (stepTimer) clearInterval(stepTimer);
            if (!observeOnly){
                // Kick immediately so manual runs start pulling right away
                doStepTick();
            
                // Then keep stepping on interval
                stepTimer = setInterval(doStepTick, 500);
            }

	}

    async function startJob(jobType){
    	if (running) return;
    
    	runGen++;
    	running = true;
    	terminalAlertShown = false;

        cancelling = false;
        $('#ett-btn-cancel').text('Cancel');

        $('#ett-btn-cancel').prop('disabled', false);
        
        // Disable only the button that started the job
        if (jobType === 'prices'){
          const $runBtn = $('#ett-btn-run');
          if (runBtnLabel === null) runBtnLabel = btnGetLabel($runBtn);
          $runBtn.prop('disabled', true);
          btnSetLabel($runBtn, 'Running...');
        } else if (jobType === 'typeids'){
          const $genBtn = $('#ett-btn-generate');
          if (genBtnLabel === null) genBtnLabel = btnGetLabel($genBtn);
          $genBtn.prop('disabled', true);
          btnSetLabel($genBtn, 'Generating...');
        }

		jobStartedAtMs = Date.now();
		jobElapsedFinalSecs = null;
		startElapsedTicker();

		const res = await ajax('POST', {
			action: 'ett_job_start',
			job_type: jobType,
			_ajax_nonce: ETT_ADMIN.nonce
		});

        if (!res || !res.success){
          running = false;
          $('#ett-btn-cancel').prop('disabled', true);
        
          // Restore button labels on start failure
          if (jobType === 'prices' && runBtnLabel !== null){
            btnSetLabel($('#ett-btn-run'), runBtnLabel);
            $('#ett-btn-run').prop('disabled', false);
          }
          if (jobType === 'typeids' && genBtnLabel !== null){
            btnSetLabel($('#ett-btn-generate'), genBtnLabel);
            $('#ett-btn-generate').prop('disabled', false);
          }
        
          alert('Failed to start job.');
          return;
        }

		jobId = res.data.job_id;
		renderProgress({ phase: 'queued', last_msg: 'Job started', job_type: jobType });
		observeOnly = false;

		if (jobType === 'prices'){
			setHeartbeatVisible(true);
			setHeartbeat(null);
		} else {
			setHeartbeatVisible(false);
		}

		beginMonitoring();
	}

	async function attachActiveJobOnLoad(){
		try {
			const r = await ajax('GET', {
				action: 'ett_job_active',
				_ajax_nonce: ETT_ADMIN.nonce
			});

			if (!r || !r.success || !r.data || !r.data.job) return;

			const job = r.data.job;
			const prog = job.progress || {};
			const driver = prog.driver || 'browser';

            runGen++;
			running = true;
			terminalAlertShown = false;
            cancelling = false;
            $('#ett-btn-cancel').text('Cancel');

			$('#ett-btn-cancel').prop('disabled', false);
			$('#ett-btn-run').prop('disabled', true);

			jobId = job.job_id;

			jobStartedAtMs = Date.now();
			if (job.started_at){
				const d = parseWpMysql(job.started_at);
				if (d) jobStartedAtMs = d.getTime();
			}

			jobElapsedFinalSecs = null;
			startElapsedTicker();

			observeOnly = (driver === 'cron');

			if ((prog.job_type || job.job_type) === 'prices'){
				setHeartbeatVisible(true);
				setHeartbeat(job.heartbeat_at || null);
			} else {
				setHeartbeatVisible(false);
			}

			renderProgress(prog);
			beginMonitoring();
		} catch (e){
			// ignore
		}
	}

	function startIdleAttachWatcher(){
		if (idleAttachTimer) return;

		idleAttachTimer = setInterval(async () => {
			if (running) return;

			try {
				const r = await ajax('GET', {
					action: 'ett_job_active',
					_ajax_nonce: ETT_ADMIN.nonce
				});

				if (!r || !r.success || !r.data || !r.data.job) return;
				attachActiveJobOnLoad();
			} catch (e){
				// ignore
			}
		}, 5000);
	}

    function stopJob(){
    	runGen++;
    	running = false;
    	jobId = null;

        cancelling = false;
        $('#ett-btn-cancel').text('Cancel');

		if (stepTimer) clearInterval(stepTimer);
		if (statusTimer) clearInterval(statusTimer);

		stepTimer = null;
		statusTimer = null;

		stopElapsedTicker();
		setHeartbeatVisible(false);

        $('#ett-btn-cancel').prop('disabled', true);
        
        const $runBtn = $('#ett-btn-run');
        if (runBtnLabel === null) runBtnLabel = btnGetLabel($runBtn);
        $runBtn.prop('disabled', false);
        btnSetLabel($runBtn, runBtnLabel);
        
        const $genBtn = $('#ett-btn-generate');
        if (genBtnLabel === null) genBtnLabel = btnGetLabel($genBtn);
        $genBtn.prop('disabled', false);
        btnSetLabel($genBtn, genBtnLabel);

		refreshRunHistory();
		refreshNextRun();
		refreshLastPriceRun();
	}

    async function cancelJob(){
    	if (!running || !jobId) return;
    	if (cancelling) return;
    
    	cancelling = true;
    
    	const $btn = $('#ett-btn-cancel');
    	$btn.prop('disabled', true).text('Cancelling...');
    
    	try {
    		const r = await ajax('POST', {
    			action: 'ett_job_cancel',
    			job_id: jobId,
    			_ajax_nonce: ETT_ADMIN.nonce
    		});
    
            if (r && r.success && r.data && r.data.status === 'cancelled'){
                runGen++; // invalidate any in-flight status/step responses immediately
                renderProgress({ phase: 'cancelled', last_msg: 'Cancelled by user' });
                stopJob();
                return;
            }

    		// If cancel failed, keep the job running.
    		$('#ett-job-warn').text('Cancel request did not complete. Job is still running.').show();
    		$btn.prop('disabled', false).text('Cancel');
    		cancelling = false;
    	} catch (e){
    		$('#ett-job-warn').text('Cancel request failed (network/server). Job is still running.').show();
    		$btn.prop('disabled', false).text('Cancel');
    		cancelling = false;
    	}
    }

	function updateToggleIcon(li){
		const toggle = li.children('.ett-node').find('.ett-toggle');
		const children = li.children('.ett-children');
		if (!toggle.length) return;
		toggle.text(children.is(':visible') ? '▾' : '▸');
	}

	function setDescendantsChecked(li, checked){
		li.find('.ett-children input.ett-mg').prop('checked', checked);
	}

	function filterTree(q){
		q = (q || '').toLowerCase();
		const items = $('#ett-mg-tree .ett-li');

		if (!q){
			items.show();
			return;
		}

		items.each(function(){
			const name = ($(this).data('ett-name') || '');
			$(this).toggle(name.indexOf(q) >= 0);
		});
	}

	function updateSaveEnabled(){
		const any = $('input.ett-mg:checked').length > 0;
		$('#ett-save-selection').prop('disabled', !any);
	}

	function initScheduleWarn(){
		const $freq = $('select[name="freq_hours"]');
		const $warn = $('#ett-sched-rate-warning');

		const updateSchedWarn = () => {
			if (!$freq.length || !$warn.length) return;
			const v = parseInt($freq.val(), 10);
			$warn.toggle(v === 1 || v === 2);
		};

		$freq.on('change', updateSchedWarn);
		updateSchedWarn();
	}

	$(document).on('change', 'input.ett-mg', updateSaveEnabled);

	$(document).on('click', '.ett-toggle', function(){
		const li = $(this).closest('.ett-li');
		li.children('.ett-children').toggle();
		updateToggleIcon(li);
	});

	$(document).on('change', 'input.ett-mg', function(){
		const li = $(this).closest('.ett-li');
		const checked = $(this).is(':checked');
		setDescendantsChecked(li, checked);
		updateSaveEnabled();
	});

	$('#ett-mg-filter').on('input', function(){
		filterTree($(this).val());
	});

	$('#ett-btn-generate').on('click', function(e){
		e.preventDefault();
		startJob('typeids');
	});

	$('#ett-btn-run').on('click', function(e){
		e.preventDefault();
		if (esiOverall === 'Down') return;

		const last = ETT_ADMIN.last_price_run_completed_at;
		const secs = secondsSince(last);
		const isWarnEsi = (esiOverall === 'Degraded' || esiOverall === 'Recovering');

		if (isWarnEsi){
			if (secs !== null && secs < (4 * 3600)){
				showRunConfirm(' ', () => startJob('prices'));
				startConfirmTicker();
				return;
			}

			showRunConfirm(
				`ESI is currently ${esiOverall}. Running prices now may fail or be slow.\n\nRun at your own risk. Continue?`,
				() => startJob('prices')
			);
			return;
		}

		if (secs !== null && secs < (4 * 3600)){
			showRunConfirm(' ', () => startJob('prices'));
			startConfirmTicker();
			return;
		}

		startJob('prices');
	});

	$('#ett-btn-cancel').on('click', function(e){
		e.preventDefault();
		cancelJob();
	});

    $('#ett-selection-form').on('submit', async function(e){
    	e.preventDefault();
    
    	const $form = $(this);
    	const $btn = $form.find('input[type="submit"], button[type="submit"]').first();
    	const originalLabel = btnGetLabel($btn);
    
    	$btn.prop('disabled', true);
    	btnSetLabel($btn, 'Saving...');
    
    	const groups = [];
    	$('input.ett-mg:checked').each(function(){
    		groups.push($(this).val());
    	});
    
    	try {
    		const res = await ajax('POST', {
    			action: 'ett_save_selection_ajax',
    			groups: groups,
    			_ajax_nonce: ETT_ADMIN.nonce
    		});
    
    		if (res && res.success) btnFlashSaved($btn, originalLabel);
    		else btnFlashError($btn, originalLabel);
    	} catch (err){
    		btnFlashError($btn, originalLabel);
    	}
    });

    $('#ett-hubs-form').on('submit', async function(e){
    	e.preventDefault();
    
    	const $form = $(this);
    	const $btn = $form.find('input[type="submit"], button[type="submit"]').first();
    	const originalLabel = btnGetLabel($btn);
    
    	$btn.prop('disabled', true);
    	btnSetLabel($btn, 'Saving...');
    
    	const hubs = [];
    	$('input[name="ett_hubs[]"]:checked').each(function(){
    		hubs.push($(this).val());
    	});
    
    	const secondary_structure = {};
    	$('select[name^="ett_secondary_structure["]').each(function(){
    		const name = $(this).attr('name');
    		const m = name.match(/^ett_secondary_structure\[([a-z0-9_\-]+)\]$/);
    		if (!m) return;
    		secondary_structure[m[1]] = $(this).val();
    	});
    
    	const tertiary_structure = {};
    	$('select[name^="ett_tertiary_structure["]').each(function(){
    		const name = $(this).attr('name');
    		const m = name.match(/^ett_tertiary_structure\[([a-z0-9_\-]+)\]$/);
    		if (!m) return;
    		tertiary_structure[m[1]] = $(this).val();
    	});
    
    	try {
    		const res = await ajax('POST', {
    			action: 'ett_save_hubs_ajax',
    			hubs: hubs,
    			secondary_structure: secondary_structure,
    			tertiary_structure: tertiary_structure,
    			_ajax_nonce: ETT_ADMIN.nonce
    		});
    
    		if (res && res.success) btnFlashSaved($btn, originalLabel);
    		else btnFlashError($btn, originalLabel);
    	} catch (err){
    		btnFlashError($btn, originalLabel);
    	}
    });

    $('#ett-db-form').on('submit', async function(e){
      e.preventDefault();
    
      const $form = $(this);
      const $btn = $form.find('input[type="submit"], button[type="submit"]').first();
      const originalLabel = btnGetLabel($btn);
    
      $btn.prop('disabled', true);
      btnSetLabel($btn, 'Saving...');
    
      try {
        const res = await ajax('POST', {
          action: 'ett_save_db_ajax',
          host: $form.find('input[name="host"]').val(),
          port: $form.find('input[name="port"]').val(),
          dbname: $form.find('input[name="dbname"]').val(),
          user: $form.find('input[name="user"]').val(),
          pass: $form.find('input[name="pass"]').val(),
          _ajax_nonce: ETT_ADMIN.nonce
        });
    
        if (res && res.success) {
          btnFlashSaved($btn, originalLabel);
          refreshDbStatus();
        } else {
          btnFlashError($btn, originalLabel);
        }
      } catch (err) {
        btnFlashError($btn, originalLabel);
      }
    });

    // SSO settings (no page refresh)
    $('#ett-sso-form').on('submit', async function(e){
      e.preventDefault();
    
      const $form = $(this);
      const $btn = $form.find('input[type="submit"], button[type="submit"]').first();
      const originalLabel = btnGetLabel($btn);
    
      $btn.prop('disabled', true);
      btnSetLabel($btn, 'Saving...');
    
      try {
        const res = await ajax('POST', {
          action: 'ett_save_sso_ajax',
          ett_sso_client_id: $form.find('input[name="ett_sso_client_id"]').val(),
          ett_sso_client_secret: $form.find('input[name="ett_sso_client_secret"]').val(),
          _ajax_nonce: ETT_ADMIN.nonce
        });
    
        if (res && res.success) {
          btnFlashSaved($btn, originalLabel);
          refreshDbStatus();
    
          // After saving, enable/disable the Connect button based on current field values
          const clientId = ($form.find('input[name="ett_sso_client_id"]').val() || '').trim();
          const clientSecret = ($form.find('input[name="ett_sso_client_secret"]').val() || '').trim();
    
          const canConnect = (clientId !== '' && clientSecret !== '');
          const $connectBtn = $('#ett-btn-sso-connect');
          const $help = $('#ett-sso-connect-help');
    
          if ($connectBtn.length) {
            $connectBtn.prop('disabled', !canConnect);
          }
          if ($help.length) {
            $help.toggle(!canConnect);
          }
        } else {
          btnFlashError($btn, originalLabel);
        }
      } catch (err) {
        btnFlashError($btn, originalLabel);
      }
    });

    $('#ett-sched-form').on('submit', async function(e){
    	e.preventDefault();
    
    	const $form = $(this);
    
    	// Detect which submit button was clicked (Save vs Cancel)
    	const submitter = (e.originalEvent && e.originalEvent.submitter) ? e.originalEvent.submitter : null;
    	const isCancel = submitter && submitter.name === 'cancel_schedule';
    
    	const $btn = $(submitter || $form.find('input[type="submit"], button[type="submit"]').first());
    	const originalLabel = btnGetLabel($btn);
    
    	$btn.prop('disabled', true);
    	btnSetLabel($btn, 'Saving...');
    
    	try {
    		const res = await ajax('POST', Object.assign({
    			action: isCancel ? 'ett_cancel_schedule_ajax' : 'ett_save_schedule_ajax',
    			_ajax_nonce: ETT_ADMIN.nonce
    		}, isCancel ? {} : {
    			start_time: $form.find('input[name="start_time"]').val(),
    			freq_hours: $form.find('select[name="freq_hours"]').val()
    		}));
    
    		if (res && res.success){
    			if (res.data && typeof res.data.next_txt === 'string'){
    				$('#ett-next-run').text(res.data.next_txt);
    			}
    			btnFlashSaved($btn, originalLabel);
    		} else {
    			btnFlashError($btn, originalLabel);
    		}
    	} catch (err){
    		btnFlashError($btn, originalLabel);
    	}
    });

    $('#ett-fuzzwork-form').on('submit', async function(e){
    	e.preventDefault();

    	const $form = $(this);
    	const $btn = $form.find('input[type="submit"], button[type="submit"]').first();
    	const originalLabel = btnGetLabel($btn);

    	$btn.prop('disabled', true);
    	btnSetLabel($btn, 'Importing...');

    	try {
    		const res = await ajax('POST', {
    			action: 'ett_import_fuzzwork_ajax',
    			_ajax_nonce: ETT_ADMIN.nonce
    		});

    		if (res && res.success){
    			if (res.data && typeof res.data.imported_at === 'string'){
    				$('#ett-last-import').text(res.data.imported_at);
    			}
    			if (res.data && typeof res.data.details_txt === 'string'){
    				$('#ett-last-import-details').text(res.data.details_txt);
    				if (res.data.details_txt.trim() !== ''){
    					$('#ett-last-import-details-wrap').show();
    				} else {
    					$('#ett-last-import-details-wrap').hide();
    				}
    			}

    			btnSetLabel($btn, 'Import successful');
    			setTimeout(() => {
    				btnSetLabel($btn, originalLabel);
    				$btn.prop('disabled', false);
    			}, 1500);
    		} else {
    			btnFlashError($btn, originalLabel);
    		}
    	} catch (err){
    		btnFlashError($btn, originalLabel);
    	}
    });

    function btnGetLabel($btn){
      if (!$btn || !$btn.length) return '';
      return $btn.is('input') ? ($btn.val() || '') : ($btn.text() || '');
    }
    function btnSetLabel($btn, label){
      if (!$btn || !$btn.length) return;
      if ($btn.is('input')) $btn.val(label);
      else $btn.text(label);
    }
    function btnFlashSaved($btn, originalLabel, ms=1500){
      btnSetLabel($btn, 'Saved!');
      setTimeout(() => {
        btnSetLabel($btn, originalLabel);
        $btn.prop('disabled', false);
      }, ms);
    }
    function btnFlashError($btn, originalLabel, ms=2000){
      btnSetLabel($btn, 'Error');
      setTimeout(() => {
        btnSetLabel($btn, originalLabel);
        $btn.prop('disabled', false);
      }, ms);
    }
    function flashGeneratedTypeids(count){
      const $btn = $('#ett-btn-generate');
      if (!$btn.length) return;
    
      if (genBtnLabel === null) genBtnLabel = btnGetLabel($btn);
    
      const n = Number(count);
      const txt = (!isNaN(n) && isFinite(n))
        ? `Generated ${n.toLocaleString()} typeIDs`
        : 'Generated typeIDs';
    
      btnSetLabel($btn, txt);
    
      if (genBtnFlashTimer) clearTimeout(genBtnFlashTimer);
      genBtnFlashTimer = setTimeout(() => {
        btnSetLabel($btn, genBtnLabel);
        genBtnFlashTimer = null;
      }, 3000);
    }

	// Performance settings (no page refresh)
	$(document).on('submit', '#ett-perf-form', async function(e){
		e.preventDefault();

		const $form = $(this);
		const $btn = $form.find('button[type="submit"]');

		const pages = $form.find('input[name="batch_max_pages"]').val();
		const seconds = $form.find('input[name="batch_max_seconds"]').val();

		const originalText = $btn.text();
		$btn.prop('disabled', true).text('Saving...');

		try {
			const res = await ajax('POST', {
				action: 'ett_save_perf_ajax',
				batch_max_pages: pages,
				batch_max_seconds: seconds,
				_ajax_nonce: ETT_ADMIN.nonce
			});

			if (res && res.success) {
				$btn.text('Saved!');
				setTimeout(() => {
					$btn.text(originalText).prop('disabled', false);
				}, 1500);
			} else {
				$btn.text('Error');
				setTimeout(() => {
					$btn.text(originalText).prop('disabled', false);
				}, 2000);
			}
		} catch (err) {
			$btn.text('Error');
			setTimeout(() => {
				$btn.text(originalText).prop('disabled', false);
			}, 2000);
		}
	});

	$(document).on('change', 'input[name="ett_hubs[]"]', function(){
		const $chk = $(this);
		const $row = $chk.closest('.ett-hub-row');
		const $sels = $row.find('select.ett-hub-secondary, select.ett-hub-tertiary');
		if (!$sels.length) return;

		const authed = !!ETT_ADMIN.sso_authed;
		const hasCache = !!ETT_ADMIN.sso_cache_at;
		const enable = $chk.is(':checked') && authed && hasCache;

		$sels.prop('disabled', !enable);
		if (!enable) $sels.val('0');
	});

    function buildStructureLabel(st){
      const nm = (st && st.name) ? String(st.name) : '';
      const ticker = (st && st.owner_ticker) ? String(st.owner_ticker).trim() : '';
      const owner  = (st && st.owner_name) ? String(st.owner_name).trim() : '';
    
      let suffix = '';
      if (ticker !== '' && owner !== '') suffix = ` — [${ticker}] ${owner}`;
      else if (owner !== '') suffix = ` — ${owner}`;
    
      return nm + suffix;
    }
    
    function rebuildStructureSelects(structures){
      const pairs = (ETT_ADMIN.secondary_pairs && typeof ETT_ADMIN.secondary_pairs === 'object') ? ETT_ADMIN.secondary_pairs : {};
    
      // Group structures by solar_system_id for quick filtering
      const bySystem = {};
      (structures || []).forEach(st => {
        const sys = Number(st.solar_system_id || 0);
        const sid = Number(st.structure_id || 0);
        if (!sys || !sid) return;
        if (!bySystem[sys]) bySystem[sys] = [];
        bySystem[sys].push(st);
      });
    
      // For each hub row, rebuild both secondary + tertiary selects
      $('.ett-hub-row').each(function(){
        const $row = $(this);
        const $chk = $row.find('input[name="ett_hubs[]"]');
        if (!$chk.length) return;
    
        const hubKey = $chk.val();
        const pair = pairs[hubKey] || {};
        const pairedSystemId = Number(pair.system_id || 0);
    
        const $sec = $row.find('select.ett-hub-secondary');
        const $ter = $row.find('select.ett-hub-tertiary');
        if (!$sec.length || !$ter.length) return;
    
        const prevSec = $sec.val();
        const prevTer = $ter.val();
    
        const authed = !!ETT_ADMIN.sso_authed;
        const hasCache = !!ETT_ADMIN.sso_cache_at;
        const checked = $chk.is(':checked');
        const enable = checked && authed && hasCache;
    
        const choices = (pairedSystemId && bySystem[pairedSystemId]) ? bySystem[pairedSystemId] : [];
    
        // Build option HTML
        const secZeroText = !authed ? 'Authenticate to load structures'
                         : (!hasCache || !choices.length) ? 'Click “Refresh structures”'
                         : 'No secondary market';
    
        const terZeroText = !authed ? 'Authenticate to load structures'
                         : (!hasCache || !choices.length) ? 'Click “Refresh structures”'
                         : 'No tertiary market';
    
        let secHtml = `<option value="0">${escapeHtml(secZeroText)}</option>`;
        let terHtml = `<option value="0">${escapeHtml(terZeroText)}</option>`;
    
        for (const st of choices){
          const sid = Number(st.structure_id || 0);
          if (!sid) continue;
          const label = buildStructureLabel(st);
          secHtml += `<option value="${sid}">${escapeHtml(label)}</option>`;
          terHtml += `<option value="${sid}">${escapeHtml(label)}</option>`;
        }
    
        $sec.html(secHtml);
        $ter.html(terHtml);
    
        // Restore previous selections if still available
        if (prevSec && $sec.find(`option[value="${prevSec}"]`).length) $sec.val(prevSec);
        else $sec.val('0');
    
        if (prevTer && $ter.find(`option[value="${prevTer}"]`).length) $ter.val(prevTer);
        else $ter.val('0');
    
        $sec.prop('disabled', !enable);
        $ter.prop('disabled', !enable);
    
        if (!enable){
          $sec.val('0');
          $ter.val('0');
        }
      });
    }
    
    $('#ett-btn-refresh-structures').on('click', async function(){
      const $btn = $(this);
      const originalLabel = btnGetLabel($btn);
    
      $btn.prop('disabled', true);
      btnSetLabel($btn, 'Refreshing...');
    
      try {
        const res = await ajax('POST', {
          action: 'ett_sso_refresh_structures',
          _ajax_nonce: ETT_ADMIN.nonce
        });
    
        if (res && res.success && res.data){
        
          if (typeof res.data.cache_at === 'number') {
            ETT_ADMIN.sso_cache_at = res.data.cache_at;
          }
        
          rebuildStructureSelects(res.data.structures || []);
        
          // 🔹 Update the "Last refreshed" text immediately
          const $meta = $('#ett-structures-cache-meta');
          if ($meta.length){
            if (typeof res.data.cache_at === 'number'){
              const d = new Date(res.data.cache_at * 1000);
              const ts = d.getUTCFullYear() + '-' +
                String(d.getUTCMonth()+1).padStart(2,'0') + '-' +
                String(d.getUTCDate()).padStart(2,'0') + ' ' +
                String(d.getUTCHours()).padStart(2,'0') + ':' +
                String(d.getUTCMinutes()).padStart(2,'0') + ':' +
                String(d.getUTCSeconds()).padStart(2,'0');
        
              const count = Array.isArray(res.data.structures)
                ? res.data.structures.length
                : 0;
        
              $meta.text(`Last refreshed: ${ts} UTC. Cached structures: ${count}.`);
            } else {
              $meta.text('Structures refreshed.');
            }
          }
        
          btnSetLabel($btn, 'Refreshed!');
          setTimeout(() => {
            btnSetLabel($btn, originalLabel);
            $btn.prop('disabled', false);
          }, 1500);
        
          return;
        }

        btnSetLabel($btn, 'Error');
        setTimeout(() => {
          btnSetLabel($btn, originalLabel);
          $btn.prop('disabled', false);
        }, 2000);
      } catch (err){
        btnSetLabel($btn, 'Error');
        setTimeout(() => {
          btnSetLabel($btn, originalLabel);
          $btn.prop('disabled', false);
        }, 2000);
      }
    });

	$(function(){
		$('.ett-li').each(function(){
			const li = $(this);
			const hasChildren = li.children('.ett-children').length > 0;

			if (hasChildren){
				li.children('.ett-children').hide();
				updateToggleIcon(li);
			}
		});

		updateSaveEnabled();
		setHeartbeatVisible(false);

		if (!ETT_ADMIN.sso_authed){
			$('select.ett-hub-secondary, select.ett-hub-tertiary').prop('disabled', true).val('0');
		}

		initScheduleWarn();
		attachActiveJobOnLoad();
		startIdleAttachWatcher();

		const details = document.getElementById('ett-history-details');
		if (details){
			details.addEventListener('toggle', function(){
				if (details.open) startHistoryAutoRefresh();
				else stopHistoryAutoRefresh();
			});

			if (details.open) startHistoryAutoRefresh();
		}
	});

	$(function(){
		refreshEsiStatus();
		setInterval(refreshEsiStatus, 15000);
	});
})(jQuery);
