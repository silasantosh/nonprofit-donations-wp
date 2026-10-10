/* Read a UPI reference from a payment-success screenshot, on the donor's own phone.
 * The picture is never uploaded. The result only fills the reference box; the donor checks it,
 * and the bank statement still decides whether the gift counts. */
(function (root) {
	'use strict';
	var loading = {};
	function load(src) {
		if (!loading[src]) {
			loading[src] = new Promise(function (ok, no) {
				var s = document.createElement('script');
				s.src = src;
				s.onload = ok;
				s.onerror = function () { no(new Error('load')); };
				document.head.appendChild(s);
			});
		}
		return loading[src];
	}
	function toCanvas(file) {
		return new Promise(function (ok, no) {
			var img = new Image();
			var url = URL.createObjectURL(file);
			img.onload = function () {
				var w = img.naturalWidth, h = img.naturalHeight, max = 1300;
				if (w > max) { h = Math.round(h * max / w); w = max; }
				var c = document.createElement('canvas');
				c.width = w; c.height = h;
				c.getContext('2d').drawImage(img, 0, 0, w, h);
				URL.revokeObjectURL(url);
				ok(c);
			};
			img.onerror = function () { URL.revokeObjectURL(url); no(new Error('img')); };
			img.src = url;
		});
	}
	/**
	 * @param {Object} o input (text input to fill), host (element to append to), rupees, base (url of the ocr folder), t (texts), onread (optional callback)
	 */
	function attach(o) {
		var t = o.t, wrap = document.createElement('div');
		wrap.className = 'npd-shot';
		wrap.style.cssText = 'margin:12px 0;padding:10px;border:1px dashed #999;border-radius:8px';
		var lab = document.createElement('label');
		lab.textContent = t.pick;
		var f = document.createElement('input');
		f.type = 'file';
		f.accept = 'image/*';
		f.style.cssText = 'display:block;margin-top:6px;max-width:100%';
		lab.appendChild(f);
		var note = document.createElement('small');
		note.textContent = t.privacy;
		note.style.display = 'block';
		var msg = document.createElement('p');
		msg.setAttribute('role', 'status');
		msg.style.margin = '6px 0 0';
		wrap.appendChild(lab); wrap.appendChild(note); wrap.appendChild(msg);
		o.host.appendChild(wrap);
		f.addEventListener('change', function () {
			var file = f.files && f.files[0];
			if (!file) { return; }
			msg.textContent = t.reading;
			var worker;
			Promise.all([load(o.base + 'npd-parse.js'), load(o.base + 'tesseract.min.js'), toCanvas(file)]).then(function (r) {
				return root.Tesseract.createWorker('eng', 1, { workerPath: o.base + 'worker.min.js', corePath: o.base, langPath: o.base, gzip: false, workerBlobURL: false }).then(function (w) {
					worker = w;
					return w.recognize(r[2]);
				});
			}).then(function (res) {
				var p = root.npdParseShot(res.data.text, o.rupees);
				if (p.ref) {
					o.input.value = p.ref;
					o.input.dispatchEvent(new Event('input', { bubbles: true }));
					msg.textContent = t.found.replace('%s', p.ref) + ' ' + (p.amountOk ? t.amountOk : t.amountNo);
				} else {
					msg.textContent = t.none;
				}
				return worker.terminate();
			}).catch(function () {
				msg.textContent = t.fail;
				if (worker) { worker.terminate(); }
			});
		});
	}
	root.npdShot = { attach: attach };
})(window);
