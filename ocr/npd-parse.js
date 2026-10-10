/* Reads a UPI reference and amount out of OCR text from a payment-success screen.
 * Pure function. The result only fills the reference box for the donor to check; it never confirms anything. */
(function (root) {
	'use strict';
	function parse(text, expectedRupees) {
		var t = String(text || '').replace(/[Oo](?=\d{3})/g, '0');
		var lines = t.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
		var D12 = /(?:^|[^\w.@])(\d{12})(?![\w@])/;
		var D12g = /(?:^|[^\w.@])(\d{12})(?![\w@])/g;
		var ref = '', how = '';
		// 1. A label (UTR / UPI ref / transaction ID) followed by the number on the same or next line.
		var lab = /(utr|upi\s*(ref|transaction|txn)[a-z .]*|ref(erence)?\s*(no|number|id)?|transaction\s*id|txn\s*id)/i;
		for (var i = 0; i < lines.length && !ref; i++) {
			if (!lab.test(lines[i])) { continue; }
			var seg = lines[i] + ' ' + (lines[i + 1] || '');
			var m = seg.match(D12);
			if (m) { ref = m[1]; how = 'label'; }
		}
		// 2. Any lone 12-digit number (UPI reference numbers are 12 digits).
		if (!ref) {
			var all = [], mm;
			while ((mm = D12g.exec(t)) !== null) { all.push(mm[1]); D12g.lastIndex = mm.index + mm[0].length - 1; }
			var uniq = all.filter(function (v, i2) { return all.indexOf(v) === i2; });
			if (uniq.length === 1) { ref = uniq[0]; how = 'twelve'; }
		}
		// 3. Letters+digits ids some apps print (for example a UTR starting with a bank code).
		if (!ref) {
			var m3 = t.match(/\b(?:UTR|Ref(?:erence)?)[^A-Za-z0-9]{0,4}([A-Z0-9]{10,22})\b/);
			if (m3) { ref = m3[1]; how = 'alnum'; }
		}
		// Amount check: the expected rupee figure appears next to a currency mark or on its own big line.
		var ok = false;
		if (expectedRupees) {
			var want = String(expectedRupees).replace(/,/g, '');
			var nums = t.replace(/,/g, '').match(/\d+(?:\.\d{1,2})?/g) || [];
			ok = nums.some(function (n) { return parseFloat(n) === parseFloat(want) && n.length < 10; });
		}
		return { ref: ref, how: how, amountOk: ok };
	}
	if (typeof module !== 'undefined' && module.exports) { module.exports = { parse: parse }; }
	root.npdParseShot = parse;
})(typeof window !== 'undefined' ? window : this);
