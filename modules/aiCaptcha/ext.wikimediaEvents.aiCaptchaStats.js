/* eslint-disable */
( function ( mw ) {
	//source: https://github.com/simple-statistics/simple-statistics
	// ISC License
	//
	// Copyright (c) 2014, Tom MacWright
	//
	// Permission to use, copy, modify, and/or distribute this software for any
	// purpose with or without fee is hereby granted, provided that the above
	// copyright notice and this permission notice appear in all copies.
	//
	// THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
	// REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY AND
	// FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT,
	// INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM
	// LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR
	// OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR
	// PERFORMANCE OF THIS SOFTWARE.
	mw.aiCaptchaStats = {
		sum: sum,
		mean: mean,
		variance: variance,
		skewness: skewness,
		kurtosis: kurtosis,
		interQuartileRange: interQuartileRange
	};

	function sum(x) {

		// If the array is empty, we needn't bother computing its sum
		if (x.length === 0) {
				return 0;
		}

		// Initializing the sum as the first number in the array
		var sum = x[0];

		// Keeping track of the floating-point error correction
		var correction = 0;

		var transition;

		for (var i = 1; i < x.length; i++) {
				transition = sum + x[i];

				// Here we need to update the correction in a different fashion
				// if the new absolute value is greater than the absolute sum
				if (Math.abs(sum) >= Math.abs(x[i])) {
						correction += ((sum - transition) + x[i]);
				}
				else {
						correction += ((x[i] - transition) + sum);
				}

				sum = transition;
		}

		// Returning the corrected sum
		return sum + correction;
	}

	function mean(x) {
		// The mean of no numbers is null
		if (x.length === 0) {
				return 0;
		}

		return sum(x) / x.length;
	}

	function sumNthPowerDeviations(x, n) {
		var meanValue = mean(x),
				sum = 0,
				tempValue,
				i;

		// This is an optimization: when n is 2 (we're computing a number squared),
		// multiplying the number by itself is significantly faster than using
		// the Math.pow method.
		if (n === 2) {
				for (i = 0; i < x.length; i++) {
						tempValue = x[i] - meanValue;
						sum += tempValue * tempValue;
				}
		} else {
				for (i = 0; i < x.length; i++) {
						sum += Math.pow(x[i] - meanValue, n);
				}
		}

		return sum;
	}

	function variance(x) {
		// The variance of no numbers is null
		if (x.length === 0) {
				return 0;
		}

		// Find the mean of squared deviations between the
		// mean value and each value.
		return sumNthPowerDeviations(x, 2) / x.length;
	}

	function kurtosis(x) {

		var n = x.length;

		if (n < 4) {
				return 0;
		}

		var meanValue = mean(x);
		var tempValue;
		var secondCentralMoment = 0;
		var fourthCentralMoment = 0;

		for (var i = 0; i < n; i++) {
				tempValue = x[i] - meanValue;
				secondCentralMoment += tempValue * tempValue;
				fourthCentralMoment += tempValue * tempValue * tempValue * tempValue;
		}

		return (n - 1) / ((n - 2) * (n - 3)) *
				(n * (n + 1) * fourthCentralMoment / (secondCentralMoment * secondCentralMoment) - 3 * (n - 1));
	}

	function skewness(x) {

		if (x.length < 3) {
				return 0;
		}

		var meanValue = mean(x);
		var tempValue;
		var sumSquaredDeviations = 0;
		var sumCubedDeviations = 0;

		for (var i = 0; i < x.length; i++) {
				tempValue = x[i] - meanValue;
				sumSquaredDeviations += tempValue * tempValue;
				sumCubedDeviations += tempValue * tempValue * tempValue;
		}

		// this is Bessels' Correction: an adjustment made to sample statistics
		// that allows for the reduced degree of freedom entailed in calculating
		// values from samples rather than complete populations.
		var besselsCorrection = x.length - 1;

		// Find the mean value of that list
		var theSampleStandardDeviation = Math.sqrt(sumSquaredDeviations / besselsCorrection);

		var n = x.length,
				cubedS = Math.pow(theSampleStandardDeviation, 3);

		return n * sumCubedDeviations / ((n - 1) * (n - 2) * cubedS);
	}

	function quantile(x, p) {
		var copy = x.slice();

		if (Array.isArray(p)) {
				// rearrange elements so that each element corresponding to a requested
				// quantile is on a place it would be if the array was fully sorted
				multiQuantileSelect(copy, p);
				// Initialize the result array
				var results = [];
				// For each requested quantile
				for (var i = 0; i < p.length; i++) {
						results[i] = quantileSorted(copy, p[i]);
				}
				return results;
		} else {
				var idx = quantileIndex(copy.length, p);
				quantileSelect(copy, idx, 0, copy.length - 1);
				return quantileSorted(copy, p);
		}
	}

	function quantileSorted(x /*: Array<number> */, p /*: number */)/*: number */ {
		var idx = x.length * p;
		if (x.length === 0) {
				throw new Error('quantile requires at least one data point.');
		} else if (p < 0 || p > 1) {
				throw new Error('quantiles must be between 0 and 1');
		} else if (p === 1) {
				// If p is 1, directly return the last element
				return x[x.length - 1];
		} else if (p === 0) {
				// If p is 0, directly return the first element
				return x[0];
		} else if (idx % 1 !== 0) {
				// If p is not integer, return the next element in array
				return x[Math.ceil(idx) - 1];
		} else if (x.length % 2 === 0) {
				// If the list has even-length, we'll take the average of this number
				// and the next value, if there is one
				return (x[idx - 1] + x[idx]) / 2;
		} else {
				// Finally, in the simple case of an integer value
				// with an odd-length list, return the x value at the index.
				return x[idx];
		}
	}

	function quantileSelect(arr, k, left, right) {
		if (k % 1 === 0) {
				quickselect(arr, k, left, right);
		} else {
				k = Math.floor(k);
				quickselect(arr, k, left, right);
				quickselect(arr, k + 1, k + 1, right);
		}
	}

	function quickselect(arr/*: Array<number> */, k/*: number */, left/*: ?number */, right/*: ?number */)/*: void */ {
		left = left || 0;
		right = right || (arr.length - 1);

		while (right > left) {
				// 600 and 0.5 are arbitrary constants chosen in the original paper to minimize execution time
				if (right - left > 600) {
						var n = right - left + 1;
						var m = k - left + 1;
						var z = Math.log(n);
						var s = 0.5 * Math.exp(2 * z / 3);
						var sd = 0.5 * Math.sqrt(z * s * (n - s) / n);
						if (m - n / 2 < 0) sd *= -1;
						var newLeft = Math.max(left, Math.floor(k - m * s / n + sd));
						var newRight = Math.min(right, Math.floor(k + (n - m) * s / n + sd));
						quickselect(arr, k, newLeft, newRight);
				}

				var t = arr[k];
				var i = left;
				var j = right;

				swap(arr, left, k);
				if (arr[right] > t) swap(arr, left, right);

				while (i < j) {
						swap(arr, i, j);
						i++;
						j--;
						while (arr[i] < t) i++;
						while (arr[j] > t) j--;
				}

				if (arr[left] === t) swap(arr, left, j);
				else {
						j++;
						swap(arr, j, right);
				}

				if (j <= k) left = j + 1;
				if (k <= j) right = j - 1;
		}
	}

	function swap(arr, i, j) {
		var tmp = arr[i];
		arr[i] = arr[j];
		arr[j] = tmp;
	}

	function multiQuantileSelect(arr, p) {
		var indices = [0];
		for (var i = 0; i < p.length; i++) {
				indices.push(quantileIndex(arr.length, p[i]));
		}
		indices.push(arr.length - 1);
		indices.sort(compare);

		var stack = [0, indices.length - 1];

		while (stack.length) {
				var r = Math.ceil(stack.pop());
				var l = Math.floor(stack.pop());
				if (r - l <= 1) continue;

				var m = Math.floor((l + r) / 2);
				quantileSelect(arr, indices[m], indices[l], indices[r]);

				stack.push(l, m, m, r);
		}
	}

	function compare(a, b) {
		return a - b;
	}

	function quantileIndex(len /*: number */, p /*: number */)/*:number*/ {
		var idx = len * p;
		if (p === 1) {
				// If p is 1, directly return the last index
				return len - 1;
		} else if (p === 0) {
				// If p is 0, directly return the first index
				return 0;
		} else if (idx % 1 !== 0) {
				// If index is not integer, return the next index in array
				return Math.ceil(idx) - 1;
		} else if (len % 2 === 0) {
				// If the list has even-length, we'll return the middle of two indices
				// around quantile to indicate that we need an average value of the two
				return idx - 0.5;
		} else {
				// Finally, in the simple case of an integer index
				// with an odd-length list, return the index
				return idx;
		}
	}

	function interQuartileRange(x) {
		// Interquartile range is the span between the upper quartile,
		// at `0.75`, and lower quartile, `0.25`
		if(x.length<4) {
			return 0;
		}

		var q1 = quantile(x, 0.75),
				q2 = quantile(x, 0.25);

		if (typeof q1 === 'number' && typeof q2 === 'number') {
				return q1 - q2;
		}
	}
}( mediaWiki ) );