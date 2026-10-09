/* Nonprofit Donations - donate form. No dependencies. */
(function () {
	'use strict';
	var cfg = window.NPD || {};
	var t = cfg.i18n || {};

	function post(path, data) {
		return fetch(cfg.api + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(data)
		}).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw new Error((j && j.message) || t.error); }
				return j;
			});
		});
	}

	function setup(wrap) {
		var form = wrap.querySelector('.npd-form');
		var msg = form.querySelector('.npd-msg');
		var btn = form.querySelector('.npd-submit');
		var other = form.querySelector('.npd-other');
		var want = form.querySelector('[name=want_80g]');
		var panRow = form.querySelector('.npd-pan');

		function say(text, isError) {
			msg.textContent = text || '';
			msg.className = 'npd-msg' + (isError ? ' is-error' : '');
		}
		function busy(on) {
			btn.disabled = on;
			btn.textContent = on ? t.working : t.donate;
		}
		function amount() {
			var c = form.querySelector('[name=amount_choice]:checked');
			if (!c) { return 0; }
			return c.value === 'other' ? parseInt(other.value, 10) || 0 : parseInt(c.value, 10);
		}

		form.querySelectorAll('[name=amount_choice]').forEach(function (r) {
			r.addEventListener('change', function () { other.hidden = (r.value !== 'other' || !r.checked); });
		});
		if (want && panRow) {
			want.addEventListener('change', function () { panRow.hidden = !want.checked; });
		}

		function showUpi(d) {
			busy(false);
			form.hidden = true;
			var box = document.createElement('div');
			box.className = 'npd-upi';
			var h = document.createElement('p');
			h.textContent = t.upiVpa + ' ' + d.vpa + ' - Rs ' + d.amount;
			var a = document.createElement('a');
			a.className = 'wp-element-button npd-submit npd-upi-btn';
			a.href = d.upi_link;
			a.textContent = t.upiPay;
			var qrNote = document.createElement('p');
			qrNote.textContent = t.upiScan;
			var qrBox = document.createElement('div');
			qrBox.className = 'npd-qr';
			if (window.qrcode) {
				var q = window.qrcode(0, 'M');
				q.addData(d.upi_link);
				q.make();
				qrBox.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
			}
			var lab = document.createElement('label');
			lab.textContent = t.upiUtr;
			lab.className = 'npd-optional';
			var inp = document.createElement('input');
			inp.type = 'text';
			inp.inputMode = 'numeric';
			inp.autocomplete = 'off';
			inp.maxLength = 30;
			lab.appendChild(inp);
			var go = document.createElement('button');
			go.type = 'button';
			go.className = 'wp-element-button npd-submit';
			go.textContent = t.upiSend;
			var out = document.createElement('p');
			out.className = 'npd-msg';
			out.setAttribute('role', 'status');
			go.addEventListener('click', function () {
				go.disabled = true;
				post('upi-submit', { donation_id: d.donation_id, token: d.token, utr: inp.value }).then(function () {
					box.textContent = t.upiDone;
					box.className = 'npd-msg';
				}).catch(function (err) {
					go.disabled = false;
					out.textContent = err.message;
					out.className = 'npd-msg is-error';
				});
			});
			[h, a, qrNote, qrBox, lab, go, out].forEach(function (n) { box.appendChild(n); });
			wrap.appendChild(box);
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			say('');
			var payload = {
				amount: amount(),
				name: form.name.value,
				email: form.email.value,
				phone: form.phone.value,
				consent: form.consent.checked ? 1 : 0,
				website: form.website.value,
				ts: form.ts.value,
				campaign: wrap.getAttribute('data-campaign') || '',
				want_80g: want && want.checked ? 1 : 0,
				pan: form.pan ? form.pan.value : ''
			};
			busy(true);
			post('donate', payload).then(function (d) {
				if (d.mode === 'upi') { return showUpi(d); }
				if (d.mode === 'demo') {
					busy(false);
					say(t.demoAsk);
					btn.textContent = t.demoBtn;
					btn.onclick = function (ev) {
						ev.preventDefault();
						btn.onclick = null;
						busy(true);
						post('demo-confirm', { donation_id: d.donation_id, token: d.token }).then(function () {
							busy(false);
							say(t.demoThanks);
							form.reset();
						}).catch(function (err) { busy(false); say(err.message, true); });
					};
					return;
				}
				var rz = new window.Razorpay({
					key: d.key_id,
					amount: d.amount,
					currency: 'INR',
					name: d.name || document.title,
					order_id: d.order_id,
					prefill: d.prefill,
					handler: function (res) {
						post('confirm', {
							donation_id: d.donation_id,
							razorpay_order_id: res.razorpay_order_id,
							razorpay_payment_id: res.razorpay_payment_id,
							razorpay_signature: res.razorpay_signature
						}).then(function () {
							busy(false);
							say(t.thanks);
							form.reset();
						}).catch(function () { busy(false); say(t.verify, true); });
					},
					modal: { ondismiss: function () { busy(false); } }
				});
				rz.open();
			}).catch(function (err) { busy(false); say(err.message, true); });
		});
	}

	document.querySelectorAll('.npd-wrap').forEach(setup);
})();
