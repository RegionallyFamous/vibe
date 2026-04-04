/* eslint-env browser */
/**
 * Front-end quiz; share/save are native `<a href>` (REST OG JPEG + social intents).
 */
import { __, sprintf } from '@wordpress/i18n';

/** Guard against huge `data-quiz` (see VIBE_CHECK_MAX_CLIENT_QUIZ_JSON_BYTES in PHP). */
const VIBE_CHECK_MAX_DATA_QUIZ_BYTES = 655360;

/** Max length for `?quiz_result=` (aligned with REST `result_id` validation). */
const VIBE_CHECK_QUIZ_RESULT_PARAM_MAX = 64;

/**
 * @param {string} id
 * @return {boolean}
 */
function vibeCheckIsValidResultIdParam( id ) {
	if ( typeof id !== 'string' ) {
		return false;
	}
	if ( id.length === 0 || id.length > VIBE_CHECK_QUIZ_RESULT_PARAM_MAX ) {
		return false;
	}
	return /^[a-z0-9_-]+$/i.test( id );
}

/** Answer row fade out before swapping in feedback (ms). */
const VIBE_CHECK_FEEDBACK_SWAP_MS = 200;

/**
 * Must match `.vibe-check-answer-feedback--inline` transition duration in style.scss.
 */
const VIBE_CHECK_FEEDBACK_ENTER_MS = 480;

/**
 * Extra time to read feedback after the enter animation (scaled by text length).
 * Tuned between “too fast to read” and the older long hold.
 *
 * @param {string} text
 * @return {number}
 */
function vibeCheckFeedbackReadMs( text ) {
	const len = text.length;
	return Math.min( 3600, Math.max( 1000, 480 + len * 24 ) );
}

/**
 * @param {string} str
 */
function slugify( str ) {
	return String( str || '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' );
}

/**
 * Quiz JSON may use string or number outcome ids; DOM attributes are always strings.
 *
 * @param {unknown} a
 * @param {unknown} b
 */
function vibeCheckSameResultId( a, b ) {
	return String( a ) === String( b );
}

/**
 * Server-localized strings from `vibe_check_share_strings` / `vibe_check_share_hashtags`.
 *
 * @return {{ invitationShort: string, invitationLong: string, hashtags: string }}
 */
function vibeCheckGetShareConfig() {
	const w =
		typeof window !== 'undefined' && window.vibeCheckShare &&
		typeof window.vibeCheckShare === 'object'
			? window.vibeCheckShare
			: {};
	return {
		invitationShort:
			typeof w.invitationShort === 'string' && w.invitationShort.trim() !== ''
				? w.invitationShort
				: __(
						'Take the quiz—what’s your result?',
						'vibe-check'
				  ),
		invitationLong:
			typeof w.invitationLong === 'string' && w.invitationLong.trim() !== ''
				? w.invitationLong
				: __(
						'Take the quiz and compare your result!',
						'vibe-check'
				  ),
		hashtags:
			typeof w.hashtags === 'string' ? w.hashtags.trim() : '',
	};
}

/**
 * Truncate for X “text” param (URL is passed separately).
 *
 * @param {string} text
 * @param {number} maxLen
 * @return {string}
 */
function vibeCheckTruncateTweetText( text, maxLen ) {
	if ( text.length <= maxLen ) {
		return text;
	}
	const ell = '\u2026';
	const n = Math.max( 0, maxLen - ell.length );
	return text.slice( 0, n ).trimEnd() + ell;
}

/**
 * @param {{ title: string }} quiz
 * @param {string}          resultTitle
 * @return {{ bragLine: string, tweetText: string }}
 */
function vibeCheckBuildShareStrings( quiz, resultTitle ) {
	const cfg = vibeCheckGetShareConfig();
	const qtitle = typeof quiz.title === 'string' ? quiz.title : '';
	const bragLine = sprintf(
		/* translators: 1: result title, 2: quiz title */
		__( 'I got "%1$s" on the %2$s quiz!', 'vibe-check' ),
		resultTitle,
		qtitle
	);
	const hashtags = cfg.hashtags;
	const forTweet = `${ bragLine } ${ cfg.invitationShort }`;
	const forTweetWithTags =
		hashtags.length > 0 ? `${ forTweet }\n\n${ hashtags }` : forTweet;
	const tweetText = vibeCheckTruncateTweetText( forTweetWithTags, 230 );
	return {
		bragLine,
		tweetText,
	};
}

/**
 * Share-intent URLs for X, Facebook, and Reddit.
 *
 * @param {{ bragLine: string, tweetText: string }} sharePack
 * @param {string} shareUrlStr
 * @return {{ twitter: string, facebook: string, reddit: string }}
 */
function vibeCheckBuildShareIntentUrls( sharePack, shareUrlStr ) {
	const encShareUrl = encodeURIComponent( shareUrlStr );
	const encTweet = encodeURIComponent( sharePack.tweetText );
	const tagLine = vibeCheckGetShareConfig().hashtags.trim();
	const redditLine =
		tagLine.length > 0
			? `${ sharePack.bragLine } ${ tagLine }`.replace( /\s+/g, ' ' ).trim()
			: sharePack.bragLine;
	const redditTitle = vibeCheckTruncateTweetText( redditLine, 280 );
	const encRedditTitle = encodeURIComponent( redditTitle );
	return {
		twitter: `https://twitter.com/intent/tweet?text=${ encTweet }&url=${ encShareUrl }`,
		facebook: `https://www.facebook.com/sharer/sharer.php?u=${ encShareUrl }`,
		reddit: `https://www.reddit.com/submit?url=${ encShareUrl }&title=${ encRedditTitle }`,
	};
}

const VIBE_CHECK_SVG_NS =
	'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true" focusable="false" class="vibe-check-share-icon"';

/**
 * Inline SVG for share chips (no network, CSP-safe).
 *
 * @param {'x'|'facebook'|'reddit'|'download'} id
 * @return {string} Raw SVG HTML (trusted paths only).
 */
function vibeCheckShareIconSvg( id ) {
	switch ( id ) {
		case 'x':
			return `<svg ${ VIBE_CHECK_SVG_NS }><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>`;
		case 'facebook':
			return `<svg ${ VIBE_CHECK_SVG_NS }><path d="M24 12.073C24 5.446 18.627 0 12 0S0 5.446 0 12.073c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>`;
		case 'reddit':
			return `<svg ${ VIBE_CHECK_SVG_NS }><path d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm6.5 11.9c0 .9-.7 1.6-1.6 1.6-.9 0-1.6-.7-1.6-1.6 0-.9.7-1.6 1.6-1.6.9 0 1.6.7 1.6 1.6zm-13 0c0 .9-.7 1.6-1.6 1.6-.9 0-1.6-.7-1.6-1.6 0-.9.7-1.6 1.6-1.6.9 0 1.6.7 1.6 1.6zm6.5 4.5c-2.2 0-4.1-1.2-5.1-3h10.2c-1 1.8-2.9 3-5.1 3z"/></svg>`;
		case 'download':
			return `<svg ${ VIBE_CHECK_SVG_NS }><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>`;
		default:
			return `<svg ${ VIBE_CHECK_SVG_NS }><circle cx="12" cy="12" r="10"/></svg>`;
	}
}

/**
 * @param {{ title: string }} quiz
 * @param {string}         winnerId
 */
function resultImageBaseName( quiz, winnerId ) {
	const q = slugify( quiz.title || 'quiz' );
	const w = slugify( winnerId || 'result' );
	const d = new Date();
	const ymd = `${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( d.getDate() ).padStart( 2, '0' ) }`;
	return `${ q }-${ w }-${ ymd }.jpg`;
}

/**
 * @param {string} s
 */
function escHtml( s ) {
	const div = document.createElement( 'div' );
	div.textContent = s;
	return div.innerHTML;
}

/**
 * Turn plain-text result copy into readable HTML (paragraphs, optional lead line).
 *
 * @param {string} raw
 * @return {string} Safe HTML (no unescaped user text).
 */
function formatResultDescriptionHtml( raw ) {
	const text =
		typeof raw === 'string' ? raw.trim().replace( /\r\n/g, '\n' ) : '';
	if ( ! text ) {
		return '';
	}

	/**
	 * @param {string} prose
	 * @return {string[]}
	 */
	function chunkLongProse( prose ) {
		if ( prose.length < 380 ) {
			return [ prose ];
		}
		const parts = prose.split( /(?<=[.!?])\s+(?=[A-Z"'])/ );
		if ( parts.length < 4 ) {
			return [ prose ];
		}
		const out = [];
		for ( let i = 0; i < parts.length; i += 3 ) {
			out.push( parts.slice( i, i + 3 ).join( ' ' ).trim() );
		}
		return out;
	}

	/**
	 * @param {string} block
	 * @return {string}
	 */
	function paragraphsFromBlock( block ) {
		const b = block.trim();
		if ( ! b ) {
			return '';
		}
		const double = b
			.split( /\n\n+/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		const pieces =
			double.length > 1
				? double
				: b.split( /\n/ ).map( ( s ) => s.trim() ).filter( Boolean );
		const blocks =
			pieces.length > 1 ? pieces : chunkLongProse( pieces[ 0 ] || b );
		return blocks
			.map(
				( p ) =>
					`<p class="vibe-check-result-desc-p">${ escHtml( p ) }</p>`
			)
			.join( '' );
	}

	if ( ! /\n/.test( text ) ) {
		const m = text.match( /^(.{1,240}[.!?])\s+(.+)$/ );
		if ( m && m[ 2 ] && m[ 2 ].trim().length > 72 ) {
			const lead = `<p class="vibe-check-result-desc-lead">${ escHtml(
				m[ 1 ].trim()
			) }</p>`;
			const bodyInner = paragraphsFromBlock( m[ 2 ] );
			return `${ lead }<div class="vibe-check-result-desc-body">${ bodyInner }</div>`;
		}
	}

	return `<div class="vibe-check-result-desc-body">${ paragraphsFromBlock(
		text
	) }</div>`;
}

/**
 * Plain text for a truncated result description (word-aware + ellipsis).
 *
 * @param {string} full   Trimmed source.
 * @param {number} endIdx Exclusive end index in `full` (1 … full.length).
 * @return {string}
 */
function vibeCheckPlainResultDescriptionForIndex( full, endIdx ) {
	if ( endIdx >= full.length ) {
		return full;
	}
	let s = full.slice( 0, endIdx ).trimEnd();
	const lastSpace = s.lastIndexOf( ' ' );
	if ( lastSpace >= 16 ) {
		s = s.slice( 0, lastSpace ).trimEnd();
	}
	if ( ! s ) {
		s = full.slice( 0, Math.min( 1, full.length ) ).trimEnd();
	}
	return `${ s }\u2026`;
}

/**
 * @param {HTMLElement} wrap `.vibe-check-result-desc-wrap`
 * @return {boolean}
 */
function vibeCheckResultDescWrapContentFits( wrap ) {
	return wrap.scrollHeight <= wrap.clientHeight + 2;
}

/**
 * Shrink formatted description until it fits the flex-allocated box (no scroll).
 *
 * @param {HTMLElement} wrap       `.vibe-check-result-desc-wrap`
 * @param {string}      fullPlain  Full description (trimmed by caller).
 */
function vibeCheckFitResultDescriptionInPlace( wrap, fullPlain ) {
	const full = fullPlain.trim();
	wrap.removeAttribute( 'title' );
	wrap.style.removeProperty( 'height' );
	wrap.style.removeProperty( 'minHeight' );
	if ( ! full ) {
		wrap.innerHTML = '';
		return;
	}

	wrap.innerHTML = formatResultDescriptionHtml( full );
	// Ensure layout uses content height (not a stale stretched flex box).
	void wrap.offsetHeight;
	if ( vibeCheckResultDescWrapContentFits( wrap ) ) {
		return;
	}

	let lo = 1;
	let hi = full.length;
	let bestPlain = '\u2026';
	let bestMid = 0;

	while ( lo <= hi ) {
		const mid = Math.floor( ( lo + hi ) / 2 );
		const plain = vibeCheckPlainResultDescriptionForIndex( full, mid );
		wrap.innerHTML = formatResultDescriptionHtml( plain );
		if ( vibeCheckResultDescWrapContentFits( wrap ) ) {
			bestPlain = plain;
			bestMid = mid;
			lo = mid + 1;
		} else {
			hi = mid - 1;
		}
	}

	if ( bestMid === 0 ) {
		wrap.innerHTML = formatResultDescriptionHtml( '\u2026' );
		wrap.title = full;
		return;
	}
	wrap.innerHTML = formatResultDescriptionHtml( bestPlain );
	if ( bestPlain !== full ) {
		wrap.title = full;
	}
}

/**
 * @param {HTMLElement} el
 * @param {() => void}  cb
 */
function vibeCheckWhenElementHasLayoutHeight( el, cb ) {
	let frames = 0;
	function tick() {
		if ( el.clientHeight > 0 || frames++ >= 32 ) {
			cb();
			return;
		}
		requestAnimationFrame( tick );
	}
	requestAnimationFrame( tick );
}

/**
 * Measure after paint and on resize; returns disconnect/cleanup.
 *
 * @param {HTMLElement} resultSection `.vibe-check-result`
 * @param {string}      fullPlain
 * @return {() => void}
 */
function vibeCheckScheduleResultDescriptionFit( resultSection, fullPlain ) {
	const wrap = resultSection.querySelector( '.vibe-check-result-desc-wrap' );
	if ( ! wrap || typeof fullPlain !== 'string' || ! fullPlain.trim() ) {
		return () => {};
	}

	const text = fullPlain.trim();
	let cancelled = false;
	let debounceId = 0;
	const debounceMs = 100;

	const run = () => {
		if ( cancelled ) {
			return;
		}
		vibeCheckWhenElementHasLayoutHeight( wrap, () => {
			if ( cancelled ) {
				return;
			}
			const fontsReady =
				document.fonts && document.fonts.ready
					? document.fonts.ready
					: Promise.resolve();
			void fontsReady.then( () => {
				if ( ! cancelled ) {
					vibeCheckFitResultDescriptionInPlace( wrap, text );
				}
			} );
		} );
	};

	const debouncedRun = () => {
		window.clearTimeout( debounceId );
		debounceId = window.setTimeout( run, debounceMs );
	};

	/** @type {ResizeObserver | null} */
	let ro = null;
	try {
		if ( typeof ResizeObserver !== 'undefined' ) {
			ro = new ResizeObserver( debouncedRun );
			const cardInner = resultSection.querySelector(
				'.vibe-check-result-card-inner'
			);
			if ( cardInner ) {
				ro.observe( cardInner );
			}
		}
	} catch {
		ro = null;
	}

	window.addEventListener( 'resize', debouncedRun, { passive: true } );

	requestAnimationFrame( () => {
		requestAnimationFrame( run );
	} );

	return () => {
		cancelled = true;
		try {
			ro?.disconnect();
		} catch {
			// ignore
		}
		window.removeEventListener( 'resize', debouncedRun );
		window.clearTimeout( debounceId );
		wrap.removeAttribute( 'title' );
	};
}

/**
 * Escape for HTML attribute.
 *
 * @param {string} s
 */
function escAttr( s ) {
	return String( s ?? '' )
		.replace( /&/g, '&amp;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' )
		.replace( /</g, '&lt;' );
}

/**
 * Stable per-outcome colors for the result top bar (matches prototype intent).
 *
 * @param {string} id Result id.
 * @return {{ banner: string, soft: string }}
 */
function vibeCheckColorsFromResultId( id ) {
	let h = 0;
	const s = String( id || '' );
	for ( let i = 0; i < s.length; i++ ) {
		h = ( Math.imul( 31, h ) + s.charCodeAt( i ) ) | 0;
	}
	const hue = Math.abs( h ) % 360;
	return {
		banner: `hsl(${ hue } 52% 38%)`,
		soft: `hsl(${ hue } 34% 93%)`,
	};
}

/**
 * @param {number} current 0-based index
 * @param {number} total
 */
function vibeCheckProgressRingSvg( current, total ) {
	const r = 18;
	const circ = 2 * Math.PI * r;
	const pct = total > 0 ? ( current + 1 ) / total : 0;
	const offset = circ - pct * circ;
	return `<svg class="vibe-check-progress-ring" width="44" height="44" viewBox="0 0 44 44" aria-hidden="true"><circle class="vibe-check-progress-ring-track" cx="22" cy="22" r="${ r }" fill="none" stroke-width="4" /><circle class="vibe-check-progress-ring-fill" cx="22" cy="22" r="${ r }" fill="none" stroke-width="4" stroke-linecap="round" stroke-dasharray="${ circ }" stroke-dashoffset="${ offset }" transform="rotate(-90 22 22)" /></svg>`;
}

/**
 * @param {HTMLElement} resultRoot .vibe-check-result
 */
function vibeCheckMountConfetti( resultRoot ) {
	try {
		if (
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		) {
			return;
		}
	} catch {
		return;
	}
	const layer = document.createElement( 'div' );
	layer.className = 'vibe-check-confetti';
	layer.setAttribute( 'aria-hidden', 'true' );
	for ( let i = 0; i < 36; i++ ) {
		const p = document.createElement( 'span' );
		p.className = 'vibe-check-confetti-piece';
		const hue = Math.floor( Math.random() * 360 );
		const x = ( Math.random() - 0.5 ) * 420;
		const y = - ( Math.random() * 360 + 80 );
		const rot = Math.random() * 720 - 360;
		const t = 1.2 + Math.random() * 0.9;
		p.style.setProperty( '--vc-cx', `${ x }px` );
		p.style.setProperty( '--vc-cy', `${ y }px` );
		p.style.setProperty( '--vc-cr', `${ rot }deg` );
		p.style.setProperty( '--vc-h', `${ hue }` );
		p.style.animationDuration = `${ t }s`;
		p.style.animationDelay = `${ Math.random() * 0.25 }s`;
		layer.appendChild( p );
	}
	resultRoot.prepend( layer );
	window.setTimeout( () => layer.remove(), 3200 );
}

/**
 * Only allow http(s) URLs for result CTAs (matches server sanitization).
 *
 * @param {string} u
 */
function isSafeHttpUrl( u ) {
	try {
		const x = new URL( u, window.location.href );
		return x.protocol === 'http:' || x.protocol === 'https:';
	} catch {
		return false;
	}
}

/**
 * @param {unknown} quiz Parsed JSON.
 * @return {boolean} Whether the quiz is safe to run.
 */
function validateQuiz( quiz ) {
	if ( ! quiz || typeof quiz !== 'object' ) {
		return false;
	}
	const q = /** @type {{ results?: unknown, questions?: unknown }} */ (
		quiz
	);
	if ( ! Array.isArray( q.results ) || q.results.length === 0 ) {
		return false;
	}
	if ( ! Array.isArray( q.questions ) || q.questions.length === 0 ) {
		return false;
	}
	const ids = new Set(
		q.results
			.map( ( r ) =>
				r && typeof r === 'object' && 'id' in r
					? String( /** @type {{ id: string }} */ ( r ).id )
					: ''
			)
			.filter( ( id ) => id !== '' )
	);
	if ( ids.size === 0 ) {
		return false;
	}
	let answersPerQuestion = null;
	for ( const row of q.questions ) {
		if (
			! row ||
			typeof row !== 'object' ||
			! Array.isArray( row.answers )
		) {
			return false;
		}
		if ( row.answers.length === 0 ) {
			return false;
		}
		if (
			answersPerQuestion !== null &&
			row.answers.length !== answersPerQuestion
		) {
			return false;
		}
		answersPerQuestion = row.answers.length;
		for ( const a of row.answers ) {
			if ( ! a || typeof a !== 'object' ) {
				return false;
			}
			const sc = /** @type {{ scores?: Record<string, number> }} */ ( a )
				.scores;
			if ( ! sc || typeof sc !== 'object' ) {
				return false;
			}
			for ( const id of ids ) {
				if ( ! Object.prototype.hasOwnProperty.call( sc, id ) ) {
					return false;
				}
				const v = sc[ id ];
				if ( typeof v !== 'number' || Number.isNaN( v ) ) {
					return false;
				}
			}
			for ( const k of Object.keys( sc ) ) {
				if ( ! ids.has( k ) ) {
					return false;
				}
			}
		}
	}
	return true;
}

/**
 * @param {HTMLElement|null} el
 * @param {string}           msg
 */
function announce( el, msg ) {
	if ( el && msg ) {
		el.textContent = msg;
	}
}

/**
 * @param {HTMLElement} root
 */
function initQuiz( root ) {
	const raw = root.getAttribute( 'data-quiz' );
	if ( ! raw || raw.length > VIBE_CHECK_MAX_DATA_QUIZ_BYTES ) {
		return;
	}

	let quiz;
	try {
		quiz = JSON.parse( raw );
	} catch {
		return;
	}

	if ( ! validateQuiz( quiz ) ) {
		return;
	}

	const inner = root.querySelector( '.vibe-check-inner' );
	const intro = root.querySelector( '.vibe-check-intro' );
	const introTitle = root.querySelector( '.vibe-check-intro-title' );
	const introSubtitle = root.querySelector( '.vibe-check-intro-subtitle' );
	const introMeta = root.querySelector( '.vibe-check-intro-meta' );
	const calculatingEl = root.querySelector( '.vibe-check-calculating' );
	const lastResultGroup = root.querySelector(
		'.vibe-check-last-result-group'
	);
	const lastResultEl = root.querySelector( '.vibe-check-last-result' );
	const body = root.querySelector( '.vibe-check-body' );
	const result = root.querySelector( '.vibe-check-result' );
	const startBtn = root.querySelector( '.vibe-check-start' );

	if (
		! inner ||
		! intro ||
		! body ||
		! result ||
		! startBtn ||
		! introTitle
	) {
		return;
	}

	const announcer = root.querySelector( '.vibe-check-announcer' );
	const postId = root.getAttribute( 'data-post-id' ) || '0';
	const storageKey = `vibe_check_last_${ postId }_${ slugify( quiz.title ) }`;

	introTitle.textContent = quiz.title || '';
	if (
		introSubtitle &&
		typeof quiz.subtitle === 'string' &&
		quiz.subtitle.trim()
	) {
		introSubtitle.textContent = quiz.subtitle.trim();
	}
	if ( introMeta ) {
		const nQ = quiz.questions.length;
		const nR = quiz.results.length;
		const estMin = Math.max( 1, Math.round( nQ * 0.45 ) );
		introMeta.innerHTML = `<span class="vibe-check-intro-chip">${ escHtml(
			sprintf(
				/* translators: %d: number of questions */
				__( '%d questions', 'vibe-check' ),
				nQ
			)
		) }</span><span class="vibe-check-intro-chip">${ escHtml(
			sprintf(
				/* translators: %d: estimated minutes */
				__( '~%d min', 'vibe-check' ),
				estMin
			)
		) }</span><span class="vibe-check-intro-chip">${ escHtml(
			sprintf(
				/* translators: %d: number of possible results */
				__( '%d results', 'vibe-check' ),
				nR
			)
		) }</span>`;
	}
	if ( calculatingEl ) {
		calculatingEl.hidden = true;
	}
	inner.hidden = false;

	try {
		const stored = sessionStorage.getItem( storageKey );
		if ( stored && lastResultEl ) {
			const row = JSON.parse( stored );
			if (
				row &&
				typeof row === 'object' &&
				typeof row.resultTitle === 'string'
			) {
				lastResultEl.textContent = sprintf(
					/* translators: %s: result title */
					__( 'Last time: %s', 'vibe-check' ),
					row.resultTitle
				);
				lastResultEl.hidden = false;
				lastResultGroup?.removeAttribute( 'hidden' );
				lastResultGroup?.classList.add( 'has-last-result' );
			}
		}
	} catch {
		// ignore
	}

	const state = {
		current: 0,
		/** @type {Record<string, number>} */
		scores: {},
	};

	/** @type {number|null} */
	let resultRedirectIntervalId = null;

	/** @type {(() => void) | null} */
	let resultDescFitCleanup = null;

	function clearResultRedirectTimer() {
		if ( resultRedirectIntervalId !== null ) {
			clearInterval( resultRedirectIntervalId );
			resultRedirectIntervalId = null;
		}
	}

	quiz.results.forEach( ( res ) => {
		state.scores[ res.id ] = 0;
	} );

	/**
	 * @param {() => void} done
	 */
	function runCalculatingPhase( done ) {
		intro.hidden = true;
		body.hidden = true;
		result.hidden = true;
		if ( calculatingEl ) {
			calculatingEl.hidden = false;
			const barInner = calculatingEl.querySelector(
				'.vibe-check-calculating-progress'
			);
			const barTrack = calculatingEl.querySelector(
				'.vibe-check-calculating-bar'
			);
			if ( barInner ) {
				barInner.style.width = '0%';
			}
			if ( barTrack ) {
				barTrack.setAttribute( 'aria-valuenow', '0' );
			}
			let tick = 0;
			const iv = window.setInterval( () => {
				tick += 1;
				const pct = Math.min( 100, tick * 12.5 );
				if ( barInner ) {
					barInner.style.width = `${ pct }%`;
				}
				if ( barTrack ) {
					barTrack.setAttribute(
						'aria-valuenow',
						String( Math.round( pct ) )
					);
				}
				if ( tick >= 8 ) {
					window.clearInterval( iv );
					calculatingEl.hidden = true;
					done();
				}
			}, 250 );
		} else {
			done();
		}
	}

	function showQuestion() {
		const q = quiz.questions[ state.current ];
		if ( ! q ) {
			return;
		}
		body.hidden = false;
		intro.hidden = true;
		result.hidden = true;
		if ( calculatingEl ) {
			calculatingEl.hidden = true;
		}

		const total = quiz.questions.length;
		const idx = state.current;
		const stepsHtml = Array.from( { length: total }, ( _, i ) => {
			let cls = 'vibe-check-step-dot';
			if ( i < idx ) {
				cls += ' is-done';
			}
			if ( i === idx ) {
				cls += ' is-active';
			}
			return `<span class="${ cls }" role="presentation"></span>`;
		} ).join( '' );

		const answersHtml = q.answers
			.map( ( a, i ) => {
				const label = escHtml( a.text );
				const letter =
					i < 26 ? String.fromCharCode( 65 + i ) : String( i + 1 );
				return `<button type="button" class="vibe-check-answer" data-answer-id="${ escAttr(
					a.id
				) }"><span class="vibe-check-answer-index" aria-hidden="true">${ escHtml(
					letter
				) }</span><span class="vibe-check-answer-label">${ label }</span><span class="vibe-check-answer-key" aria-hidden="true">${ escHtml(
					letter
				) }</span></button>`;
			} )
			.join( '' );

		body.classList.remove( 'vibe-check-body--enter' );
		body.innerHTML = `
			<div class="vibe-check-question-card">
				<div class="vibe-check-question-head">
					<div class="vibe-check-progress-head-left">
						${ vibeCheckProgressRingSvg( idx, total ) }
						<span class="vibe-check-progress-fraction" aria-hidden="true">${ escHtml(
							String( idx + 1 + ' / ' + total )
						) }</span>
					</div>
					<div class="vibe-check-progress-head-dots">${ stepsHtml }</div>
				</div>
				<h3 class="vibe-check-question" id="vibe-check-q-heading">${ escHtml(
					q.text
				) }</h3>
				<div class="vibe-check-answers" role="group" aria-labelledby="vibe-check-q-heading">${ answersHtml }</div>
			</div>
		`;
		window.requestAnimationFrame( () => {
			body.classList.add( 'vibe-check-body--enter' );
		} );

		announce(
			announcer,
			sprintf(
				/* translators: 1: current question number, 2: total questions, 3: question text */
				__( 'Question %1$d of %2$d. %3$s', 'vibe-check' ),
				idx + 1,
				total,
				q.text
			)
		);

		const answersWrap = body.querySelector( '.vibe-check-answers' );

		body.querySelectorAll( '.vibe-check-answer' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				if ( ! answersWrap || answersWrap.dataset.locked === '1' ) {
					return;
				}

				const aid = btn.getAttribute( 'data-answer-id' );
				const ans = q.answers.find( ( x ) => x.id === aid );
				const rawFb =
					ans && typeof ans === 'object' && 'feedback' in ans
						? /** @type {{ feedback?: string }} */ ( ans ).feedback
						: '';
				const feedback =
					typeof rawFb === 'string' ? rawFb.trim() : '';

				answersWrap.dataset.locked = '1';
				answersWrap.classList.add( 'is-locked' );
				body.querySelectorAll( '.vibe-check-answer' ).forEach( ( b ) => {
					b.disabled = true;
					b.setAttribute( 'aria-disabled', 'true' );
				} );

				if ( btn instanceof HTMLElement ) {
					btn.classList.add( 'is-selected' );
				}

				const proceed = () => {
					if ( ans && ans.scores ) {
						Object.entries( ans.scores ).forEach( ( [ k, v ] ) => {
							if ( typeof state.scores[ k ] === 'number' ) {
								state.scores[ k ] += Number( v ) || 0;
							}
						} );
					}
					state.current += 1;
					if ( state.current >= quiz.questions.length ) {
						runCalculatingPhase( () => {
							showResult();
						} );
					} else {
						showQuestion();
					}
				};

				if ( feedback ) {
					announce( announcer, feedback );
					const rowH = Math.round( btn.offsetHeight );

					btn.style.boxSizing = 'border-box';
					btn.style.transition =
						'opacity 0.2s cubic-bezier(0.4, 0, 0.2, 1), transform 0.2s cubic-bezier(0.4, 0, 0.2, 1)';
					btn.style.opacity = '0';
					btn.style.transform = 'scale(0.98) translateY(5px)';

					window.setTimeout( () => {
						const fbEl = document.createElement( 'div' );
						fbEl.className =
							'vibe-check-answer-feedback vibe-check-answer-feedback--inline';
						fbEl.setAttribute( 'role', 'status' );
						fbEl.style.boxSizing = 'border-box';
						fbEl.style.height = `${ rowH }px`;
						fbEl.style.width = '100%';

						const inner = document.createElement( 'span' );
						inner.className =
							'vibe-check-answer-feedback--inline-text';
						inner.textContent = feedback;
						fbEl.appendChild( inner );

						btn.replaceWith( fbEl );
						window.requestAnimationFrame( () => {
							window.requestAnimationFrame( () => {
								fbEl.classList.add(
									'vibe-check-answer-feedback--inline-visible'
								);
							} );
						} );

						const readMs = vibeCheckFeedbackReadMs( feedback );
						window.setTimeout(
							proceed,
							VIBE_CHECK_FEEDBACK_ENTER_MS + readMs
						);
					}, VIBE_CHECK_FEEDBACK_SWAP_MS );
					return;
				}

				btn.classList.add( 'is-vibe-check-tapped' );
				window.setTimeout( () => {
					btn.classList.remove( 'is-vibe-check-tapped' );
				}, 220 );
				proceed();
			} );
		} );
	}

	function computeWinnerFromScores() {
		const resultOrder = quiz.results.map( ( r ) => r.id );
		const entries = Object.entries( state.scores );
		entries.sort( ( a, b ) => {
			if ( b[ 1 ] !== a[ 1 ] ) {
				return b[ 1 ] - a[ 1 ];
			}
			const ia = resultOrder.indexOf( a[ 0 ] );
			const ib = resultOrder.indexOf( b[ 0 ] );
			return ( ia === -1 ? 999 : ia ) - ( ib === -1 ? 999 : ib );
		} );
		return entries[ 0 ] ? entries[ 0 ][ 0 ] : '';
	}

	function showResult() {
		clearResultRedirectTimer();
		const winner = computeWinnerFromScores();
		displayResult( winner );
	}

	function displayResult( winnerId ) {
		clearResultRedirectTimer();
		resultDescFitCleanup?.();
		resultDescFitCleanup = null;
		const r = quiz.results.find( ( res ) =>
			vibeCheckSameResultId( res.id, winnerId )
		);
		if ( ! r ) {
			return;
		}
		const winner = winnerId;

		body.hidden = true;
		result.hidden = false;
		if ( calculatingEl ) {
			calculatingEl.hidden = true;
		}

		try {
			sessionStorage.setItem(
				storageKey,
				JSON.stringify( {
					resultId: winner,
					resultTitle: r.title,
					quizTitle: quiz.title,
				} )
			);
		} catch {
			// ignore
		}

		const resRow = /** @type {{ ctaUrl?: string, ctaLabel?: string, redirect?: boolean, imageUrl?: string, imageAlt?: string, tagline?: string }} */ (
			r
		);
		const colors = vibeCheckColorsFromResultId( winner );
		const taglineRaw =
			typeof resRow.tagline === 'string' ? resRow.tagline.trim() : '';
		const ctaUrlRaw =
			typeof resRow.ctaUrl === 'string' ? resRow.ctaUrl.trim() : '';
		const safeCta = ctaUrlRaw.length > 0 && isSafeHttpUrl( ctaUrlRaw );
		const rawLab =
			typeof resRow.ctaLabel === 'string' ? resRow.ctaLabel.trim() : '';
		const ctaLabelDisp = rawLab || __( 'Continue', 'vibe-check' );
		const wantRedirect = !! resRow.redirect && safeCta;

		const imgUrl =
			typeof resRow.imageUrl === 'string' ? resRow.imageUrl.trim() : '';
		const imgAltRaw =
			typeof resRow.imageAlt === 'string' ? resRow.imageAlt.trim() : '';
		const imgAlt =
			imgAltRaw ||
			( typeof r.title === 'string' ? r.title : '' );
		const resultImageHtml =
			imgUrl && isSafeHttpUrl( imgUrl )
				? `<figure class="vibe-check-result-image-wrap vibe-check-result-reveal vibe-check-result-reveal--1"><div class="vibe-check-result-image-ring"><img src="${ escAttr(
						imgUrl
				  ) }" alt="${ escAttr(
						imgAlt
				  ) }" class="vibe-check-result-image" loading="lazy" decoding="async" width="500" height="700" /></div></figure>`
				: `<div class="vibe-check-result-image-wrap vibe-check-result-image-wrap--empty vibe-check-result-reveal vibe-check-result-reveal--1" aria-hidden="true"><div class="vibe-check-result-image-ring vibe-check-result-image-ring--placeholder"></div></div>`;

		const taglineBlock = taglineRaw
			? `<p class="vibe-check-result-tagline vibe-check-result-reveal vibe-check-result-reveal--2b">${ escHtml(
					taglineRaw
			  ) }</p>`
			: '';

		let ctaHtml = '';
		if ( wantRedirect ) {
			ctaHtml = `
				<p class="vibe-check-result-redirect-hint">${ escHtml(
					__(
						'You will be redirected shortly. Share or save your result card below first.',
						'vibe-check'
					)
				) }</p>
				<div class="vibe-check-result-cta-block" role="status" aria-live="polite">
					<p class="vibe-check-result-redirect-notice" aria-live="polite"></p>
					<div class="vibe-check-result-redirect-actions">
						<button type="button" class="vibe-check-result-go-now">${ escHtml(
							__( 'Go now', 'vibe-check' )
						) }</button>
						<button type="button" class="vibe-check-result-cancel-redirect">${ escHtml(
							__( 'Cancel redirect', 'vibe-check' )
						) }</button>
					</div>
				</div>`;
		} else if ( safeCta ) {
			ctaHtml = `
				<p class="vibe-check-result-cta-wrap">
					<a class="vibe-check-result-cta" href="${ escAttr(
						ctaUrlRaw
					) }" target="_blank" rel="noopener noreferrer">${ escHtml(
				ctaLabelDisp
			) }</a>
				</p>`;
		}

		const sharePageUrl = new URL( window.location.href );
		sharePageUrl.searchParams.set( 'quiz_result', winner );
		const shareUrlStrForLinks = sharePageUrl.toString();
		const sharePack = vibeCheckBuildShareStrings( quiz, r.title );
		const intentUrls = vibeCheckBuildShareIntentUrls(
			sharePack,
			shareUrlStrForLinks
		);

		const postIdStr = root.getAttribute( 'data-post-id' ) || '0';
		const postIdNum = parseInt( postIdStr, 10 ) || 0;
		const ogBase =
			root.getAttribute( 'data-vibe-check-og-image-endpoint' ) || '';
		let saveImageHtml = '';
		if ( postIdNum > 0 && ogBase ) {
			const saveUrl = `${ ogBase }?post_id=${ encodeURIComponent(
				String( postIdNum )
			) }&result_id=${ encodeURIComponent( String( winner ) ) }`;
			const downloadName = resultImageBaseName( quiz, winner );
			saveImageHtml = `<a href="${ escAttr(
				saveUrl
			) }" download="${ escAttr(
				downloadName
			) }" class="vibe-check-download-btn vibe-check-download-link vibe-check-share-link--icon" aria-label="${ escAttr(
				__( 'Save image', 'vibe-check' )
			) }">${ vibeCheckShareIconSvg( 'download' ) }</a>`;
		} else {
			saveImageHtml = `<span class="vibe-check-save-image-unavailable vibe-check-share-link--icon" role="img" title="${ escAttr(
				__( 'Save the image from a published post.', 'vibe-check' )
			) }" aria-label="${ escAttr(
				__( 'Save image unavailable', 'vibe-check' )
			) }">${ vibeCheckShareIconSvg( 'download' ) }</span>`;
		}

		result.setAttribute( 'data-vibe-check-winner', winner );

		result.innerHTML = `
			<div class="vibe-check-result-card" style="--vc-result-soft: ${ escAttr(
				colors.soft
			) }">
				<div class="vibe-check-result-banner" style="background: ${ escAttr(
					colors.banner
				) }" aria-hidden="true"></div>
				<div class="vibe-check-result-card-inner">
					<div class="vibe-check-result-badge">${ escHtml(
						__( 'Your Result', 'vibe-check' )
					) }</div>
					${ resultImageHtml }
					<h2 class="vibe-check-result-title vibe-check-result-reveal vibe-check-result-reveal--2" tabindex="-1">${ escHtml(
						r.title
					) }</h2>
					${ taglineBlock }
					<div class="vibe-check-result-desc-wrap vibe-check-result-reveal vibe-check-result-reveal--3">
						${ formatResultDescriptionHtml( r.description ) }
					</div>
					${ ctaHtml }
				</div>
				<div class="vibe-check-result-card-footer">
					<div class="vibe-check-share-actions">
						<a class="vibe-check-share-link vibe-check-share-link--x vibe-check-share-link--icon" href="${ escAttr(
							intentUrls.twitter
						) }" target="_blank" rel="noopener noreferrer" aria-label="${ escAttr(
							__( 'Share on X', 'vibe-check' )
						) }">${ vibeCheckShareIconSvg( 'x' ) }</a>
						<a class="vibe-check-share-link vibe-check-share-link--secondary vibe-check-share-link--icon" href="${ escAttr(
							intentUrls.facebook
						) }" target="_blank" rel="noopener noreferrer" aria-label="${ escAttr(
							__( 'Share on Facebook', 'vibe-check' )
						) }">${ vibeCheckShareIconSvg( 'facebook' ) }</a>
						<a class="vibe-check-share-link vibe-check-share-link--secondary vibe-check-share-link--icon" href="${ escAttr(
							intentUrls.reddit
						) }" target="_blank" rel="noopener noreferrer" aria-label="${ escAttr(
							__( 'Share on Reddit', 'vibe-check' )
						) }">${ vibeCheckShareIconSvg( 'reddit' ) }</a>
						${ saveImageHtml }
					</div>
					<button type="button" class="vibe-check-restart">${ escHtml(
						__( 'Retake Quiz', 'vibe-check' )
					) }</button>
				</div>
				<div class="vibe-check-result-branding">
					<span class="vibe-check-result-quiz-title">${ escHtml(
						__( 'Vibe Check', 'vibe-check' )
					) } · ${ escHtml( quiz.title ) }</span>
				</div>
			</div>
		`;

		vibeCheckMountConfetti( result );

		if ( wantRedirect && safeCta ) {
			const noticeEl = result.querySelector(
				'.vibe-check-result-redirect-notice'
			);
			const goBtn = result.querySelector( '.vibe-check-result-go-now' );
			const cancelBtn = result.querySelector(
				'.vibe-check-result-cancel-redirect'
			);
			const go = () => {
				clearResultRedirectTimer();
				window.location.assign( ctaUrlRaw );
			};
			function setRedirectNotice( secondsLeft ) {
				if ( noticeEl ) {
					noticeEl.textContent = sprintf(
						/* translators: %d: seconds until redirect */
						__( 'Redirecting in %d…', 'vibe-check' ),
						Math.max( 0, secondsLeft )
					);
				}
			}
			let left = 3;
			setRedirectNotice( left );
			goBtn?.addEventListener( 'click', go );
			cancelBtn?.addEventListener( 'click', () => {
				clearResultRedirectTimer();
				const block = result.querySelector(
					'.vibe-check-result-cta-block'
				);
				if ( block ) {
					const p = document.createElement( 'p' );
					p.className = 'vibe-check-result-redirect-cancelled';
					p.textContent = __(
						'Redirect cancelled.',
						'vibe-check'
					);
					block.replaceWith( p );
				}
			} );
			resultRedirectIntervalId = window.setInterval( () => {
				left -= 1;
				setRedirectNotice( left );
				if ( left <= 0 ) {
					go();
				}
			}, 1000 );
		}

		announce(
			announcer,
			sprintf(
				/* translators: 1: result title, 2: result description */
				__( 'Result: %1$s. %2$s', 'vibe-check' ),
				r.title,
				r.description
			)
		);

		const resultHeading = result.querySelector(
			'.vibe-check-result-title'
		);
		if ( resultHeading instanceof HTMLElement ) {
			resultHeading.focus( { preventScroll: true } );
		}

		resultDescFitCleanup = vibeCheckScheduleResultDescriptionFit(
			result,
			typeof r.description === 'string' ? r.description : ''
		);

		result
			.querySelector( '.vibe-check-restart' )
			?.addEventListener( 'click', () => {
				clearResultRedirectTimer();
				resultDescFitCleanup?.();
				resultDescFitCleanup = null;
				result.removeAttribute( 'data-vibe-check-winner' );
				state.current = 0;
				quiz.results.forEach( ( res ) => {
					state.scores[ res.id ] = 0;
				} );
				result.hidden = true;
				intro.hidden = false;
				root.querySelector( '.vibe-check-share-menu' )?.remove();
				announce( announcer, '' );
				try {
					sessionStorage.removeItem( storageKey );
				} catch {
					// ignore
				}
				if ( lastResultEl ) {
					lastResultEl.textContent = '';
					lastResultEl.hidden = true;
				}
				if ( lastResultGroup ) {
					lastResultGroup.setAttribute( 'hidden', '' );
					lastResultGroup.classList.remove( 'has-last-result' );
				}
				if ( calculatingEl ) {
					calculatingEl.hidden = true;
				}
				startBtn.focus();
			} );
	}

	try {
		const params = new URLSearchParams( window.location.search );
		const deep = params.get( 'quiz_result' );
		if (
			deep &&
			vibeCheckIsValidResultIdParam( deep ) &&
			quiz.results.some(
				( row ) => row && vibeCheckSameResultId( row.id, deep )
			)
		) {
			intro.hidden = true;
			body.hidden = true;
			displayResult( deep );
		}
	} catch {
		// ignore
	}

	startBtn.addEventListener( 'click', () => {
		showQuestion();
	} );
}

function boot() {
	document.querySelectorAll( '.vibe-check-quiz' ).forEach( ( el ) => {
		if ( el instanceof HTMLElement ) {
			initQuiz( el );
		}
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
