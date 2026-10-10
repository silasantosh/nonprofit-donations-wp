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
		var addrRow = form.querySelector('.npd-addr');

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
			r.addEventListener('change', function () {
				var wrapO = other.closest('.npd-other-wrap') || other;
				wrapO.hidden = (r.value !== 'other' || !r.checked);
				if (!wrapO.hidden) { other.focus(); if (other.scrollIntoView) { other.scrollIntoView({ block: 'center', behavior: 'smooth' }); } }
			});
		});
		if (want && panRow) {
			want.addEventListener('change', function () { panRow.hidden = !want.checked; if (addrRow) { addrRow.hidden = !want.checked; } });
		}

		function showUpi(d) {
			busy(false);
			form.hidden = true;
			var box = document.createElement('div');
			box.className = 'npd-upi';
			var h = document.createElement('p');
			h.textContent = d.vpa ? t.upiVpa + ' ' + d.vpa + ' - Rs ' + d.amount : 'Rs ' + d.amount;
			var a = document.createElement('div');
			a.className = 'npd-apps';
			var ua = navigator.userAgent || '';
			var isAndroid = /Android/i.test(ua);
			var isIOS = /iPhone|iPad|iPod/i.test(ua);
			var q = d.upi_link.indexOf('?') > -1 ? d.upi_link.slice(d.upi_link.indexOf('?') + 1) : '';
			function appLink(app) {
				if (isAndroid) {
					var pkg = { gpay: 'com.google.android.apps.nbu.paisa.user', phonepe: 'com.phonepe.app', paytm: 'net.one97.paytm', bhim: 'in.org.npci.upiapp' }[app];
					return 'intent://pay?' + q + '#Intent;scheme=upi;' + (pkg ? 'package=' + pkg + ';' : '') + 'end';
				}
				if (isIOS) {
					var sc = { gpay: 'gpay://upi/pay?', phonepe: 'phonepe://pay?', paytm: 'paytmmp://pay?', bhim: 'upi://pay?' }[app];
					return sc ? sc + q : d.upi_link;
				}
				return d.upi_link;
			}
			var apps = [['gpay', 'Google Pay'], ['phonepe', 'PhonePe'], ['paytm', 'Paytm'], ['bhim', 'BHIM'], ['any', t.upiAny || 'Any UPI app']];
			if (!d.vpa) { apps = []; }
			apps.forEach(function (x) {
				var b = document.createElement('a');
				b.className = 'npd-app npd-app-' + x[0];
				b.href = appLink(x[0]);
				b.textContent = x[1];
				a.appendChild(b);
			});
			var qrNote = document.createElement('p');
			qrNote.textContent = t.upiScan;
			var qrBox = document.createElement('div');
			qrBox.className = 'npd-qr';
			if (!d.vpa) { qrNote.hidden = true; }
			if (window.qrcode && d.vpa) {
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
			var shotHost = document.createElement('div');
			var bankBox = document.createElement('div');
			if (d.bank) {
				bankBox.className = 'npd-bank';
				bankBox.style.cssText = 'margin:14px 0;padding:12px;border:1px solid #bbb;border-radius:8px';
				var bh = document.createElement('p');
				bh.innerHTML = '<strong></strong>';
				bh.firstChild.textContent = t.bankHead;
				bankBox.appendChild(bh);
				[[t.bankName, d.bank.name], [t.bankNo, d.bank.no], ['IFSC', d.bank.ifsc], [t.bankBank, (d.bank.bank + ' ' + d.bank.branch).trim()], [t.bankRemark, d.ref]].forEach(function (r) {
					if (!r[1]) { return; }
					var row = document.createElement('p');
					row.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:4px 0';
					var l = document.createElement('span');
					l.textContent = r[0] + ': ';
					var v = document.createElement('strong');
					v.textContent = r[1];
					var cp = document.createElement('button');
					cp.type = 'button';
					cp.textContent = t.bankCopy;
					cp.addEventListener('click', function () {
						if (navigator.clipboard) { navigator.clipboard.writeText(r[1]); }
						cp.textContent = t.bankCopied;
					});
					row.appendChild(l); row.appendChild(v); row.appendChild(cp);
					bankBox.appendChild(row);
				});
				var bhelp = document.createElement('small');
				bhelp.textContent = t.bankHelp;
				bankBox.appendChild(bhelp);
			}
			[h, a, qrNote, qrBox, bankBox, shotHost, lab, go, out].forEach(function (n) { box.appendChild(n); });
			if (NPD.shot) {
				var ss = document.createElement('script');
				ss.src = NPD.shot.base + 'npd-shot.js';
				ss.onload = function () { window.npdShot.attach({ input: inp, host: shotHost, rupees: parseFloat(d.amount), base: NPD.shot.base, t: NPD.shot.t }); };
				document.head.appendChild(ss);
			}
			wrap.appendChild(box);
		}

		var stIn = form.state, stList = document.getElementById('npd-states');
		var cityIn = form.city, cityList = document.getElementById('npd-cities'), cityMap = {}, allCities = '';
		try { cityMap = JSON.parse(cityIn.getAttribute('data-cities') || '{}'); } catch (err) { cityMap = {}; }
		if (cityList) { allCities = cityList.innerHTML; }
		function fillCities(st) {
			if (!cityList) { return; }
			var list = cityMap[st], h = '', i;
			if (!list || !list.length) { cityList.innerHTML = allCities; return; }
			for (i = 0; i < list.length; i++) { h += '<option value="' + list[i].replace(/"/g, '&quot;') + '"></option>'; }
			cityList.innerHTML = h;
		}
		function pinRule() {
			var abroad = stIn.value === 'Outside India';
			form.pincode.required = !abroad;
			if (abroad) { form.pincode.removeAttribute('pattern'); } else { form.pincode.setAttribute('pattern', '[1-9][0-9]{5}'); }
		}
		form.pincode.addEventListener('input', function () { form.pincode.value = form.pincode.value.replace(/\D/g, '').slice(0, 6); });
		function canonState() {
			var v = stIn.value.trim().toLowerCase(), hit = '', i, o;
			if (stList) { for (i = 0; i < stList.options.length; i++) { o = stList.options[i].value; if (o.toLowerCase() === v) { hit = o; break; } } }
			if (hit) { stIn.value = hit; stIn.setCustomValidity(''); fillCities(hit); pinRule(); }
			else { stIn.setCustomValidity(v ? 'Please pick your state from the list.' : ''); }
			return hit;
		}
		if (stIn && stIn.tagName === 'INPUT') { stIn.addEventListener('input', canonState); stIn.addEventListener('change', function () { if (canonState() && cityIn && !cityIn.value) { cityIn.focus(); } }); stIn.addEventListener('blur', canonState); }

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			say('');
			var payload = {
				amount: amount(),
				name: form.name.value,
				email: form.email.value,
				phone: form.phone.value,
				city: form.city.value,
				state: form.state.value,
				pincode: form.pincode.value,
				consent: form.consent.checked ? 1 : 0,
				website: form.website.value,
				ts: form.ts.value,
				campaign: wrap.getAttribute('data-campaign') || '',
				want_80g: want && want.checked ? 1 : 0,
				pan: form.pan ? form.pan.value : '',
				address: form.address ? form.address.value : ''
			};
			busy(true);
			post('donate', payload).then(function (d) {
				if (d.mode === 'upi') { return showUpi(d); }
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
