/**
 * Block editor: structured outcomes + questions, Claude generation.
 */
import '@wordpress/core-data';
import apiFetch from '@wordpress/api-fetch';
import {
	useBlockProps,
	RichText,
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import {
	PanelBody,
	TextControl,
	TextareaControl,
	Button,
	Notice,
	SelectControl,
	ToggleControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * @param {string} u
 */
function isSafeHttpUrlPreview( u ) {
	try {
		const x = new URL( u, 'https://example.com' );
		return x.protocol === 'http:' || x.protocol === 'https:';
	} catch {
		return false;
	}
}

/**
 * @param {unknown} err
 * @return {string}
 */
function getRestErrorMessage( err ) {
	if ( err && typeof err === 'object' ) {
		if ( 'message' in err && err.message ) {
			return String( err.message );
		}
	}
	return __( 'Could not generate the quiz. Check your API key and try again.', 'vibe-check' );
}

/** Slug/label pairs for My Little Pony QuizResultWriter (must match PHP `vibe_check_mlp_character_choices`). */
const MLP_PONY_OPTIONS = [
	{ label: __( 'Twilight Sparkle', 'vibe-check' ), value: 'twilight-sparkle' },
	{ label: __( 'Pinkie Pie', 'vibe-check' ), value: 'pinkie-pie' },
	{ label: __( 'Rarity', 'vibe-check' ), value: 'rarity' },
	{ label: __( 'Applejack', 'vibe-check' ), value: 'applejack' },
	{ label: __( 'Fluttershy', 'vibe-check' ), value: 'fluttershy' },
	{ label: __( 'Rainbow Dash', 'vibe-check' ), value: 'rainbow-dash' },
	{ label: __( 'Spike', 'vibe-check' ), value: 'spike' },
	{ label: __( 'Princess Celestia', 'vibe-check' ), value: 'princess-celestia' },
	{ label: __( 'Princess Luna', 'vibe-check' ), value: 'princess-luna' },
	{ label: __( 'Discord', 'vibe-check' ), value: 'discord' },
	{ label: __( 'Starlight Glimmer', 'vibe-check' ), value: 'starlight-glimmer' },
	{ label: __( 'Trixie', 'vibe-check' ), value: 'trixie' },
	{ label: __( 'Sunset Shimmer', 'vibe-check' ), value: 'sunset-shimmer' },
	{ label: __( 'Derpy (Ditzy Doo)', 'vibe-check' ), value: 'derpy' },
	{ label: __( 'Big McIntosh', 'vibe-check' ), value: 'big-mcintosh' },
	{ label: __( 'Zecora', 'vibe-check' ), value: 'zecora' },
	{ label: __( 'Apple Bloom', 'vibe-check' ), value: 'apple-bloom' },
	{ label: __( 'Sweetie Belle', 'vibe-check' ), value: 'sweetie-belle' },
	{ label: __( 'Scootaloo', 'vibe-check' ), value: 'scootaloo' },
	{ label: __( 'Queen Chrysalis', 'vibe-check' ), value: 'queen-chrysalis' },
	{ label: __( 'King Sombra', 'vibe-check' ), value: 'king-sombra' },
	{ label: __( 'Lord Tirek', 'vibe-check' ), value: 'lord-tirek' },
	{ label: __( 'Cozy Glow', 'vibe-check' ), value: 'cozy-glow' },
];

/** @wordpress/components 6.8+ — opt into new defaults and silence editor deprecation notices. */
const WP_TEXT_CONTROL_PROPS = {
	__next40pxDefaultSize: true,
	__nextHasNoMarginBottom: true,
};
const WP_TEXTAREA_CONTROL_PROPS = {
	__nextHasNoMarginBottom: true,
};
const WP_TOGGLE_CONTROL_PROPS = {
	__nextHasNoMarginBottom: true,
};
const WP_SELECT_CONTROL_PROPS = {
	__next40pxDefaultSize: true,
	__nextHasNoMarginBottom: true,
};

const MIN_OUTCOMES = 3;
const MAX_OUTCOMES = 6;

/**
 * Progress hints while waiting for the REST → Anthropic round-trip (no real streaming progress available).
 *
 * @param {string} mode Generation mode.
 * @return {string[]}
 */
function getGenerationStatusMessages( mode ) {
	const step1 = __(
		'Sending your request to WordPress…',
		'vibe-check'
	);
	const step2 = __(
		'Your site is calling Anthropic Claude on the server. Your API key stays on the server and is never sent to visitors.',
		'vibe-check'
	);
	let step3;
	if ( mode === 'full' ) {
		step3 = __(
			'Claude is creating your quiz title, questions, and outcomes. First responses can take 30 seconds to a few minutes depending on length and API load.',
			'vibe-check'
		);
	} else if ( mode === 'from_outcomes' || mode === 'questions' ) {
		step3 = __(
			'Claude is writing questions and answer scoring. Busy times or long instructions can add extra wait.',
			'vibe-check'
		);
	} else {
		step3 = __(
			'Claude is rewriting your result outcomes to fit your questions.',
			'vibe-check'
		);
	}
	const step4 = __(
		'Still working—hang tight. If nothing returns after several minutes, check your server can reach api.anthropic.com and try again.',
		'vibe-check'
	);
	return [ step1, step2, step3, step4 ];
}

/**
 * @return {{ id: string, title: string, tagline: string, description: string, imageId: number, ctaUrl: string, ctaLabel: string, redirect: boolean }}
 */
function emptyOutcomeRow() {
	return {
		id: '',
		title: '',
		tagline: '',
		description: '',
		imageId: 0,
		ctaUrl: '',
		ctaLabel: '',
		redirect: false,
	};
}

/**
 * @param {unknown} results
 * @return {Array<{ id: string, title: string, tagline: string, description: string, imageId: number, ctaUrl: string, ctaLabel: string, redirect: boolean }>}
 */
function ensureOutcomeRows( results ) {
	const rows = Array.isArray( results ) ? [ ...results ] : [];
	while ( rows.length < MIN_OUTCOMES ) {
		rows.push( emptyOutcomeRow() );
	}
	return /** @type {Array<{ id: string, title: string, tagline: string, description: string, imageId: number, ctaUrl: string, ctaLabel: string, redirect: boolean }>} */ (
		rows.slice( 0, MAX_OUTCOMES ).map( ( r ) => {
			if ( r && typeof r === 'object' ) {
				const o = /** @type {{ id?: string, title?: string, tagline?: string, description?: string, imageId?: number, ctaUrl?: string, ctaLabel?: string, redirect?: boolean }} */ (
					r
				);
				const rawId =
					typeof o.imageId === 'number' && ! Number.isNaN( o.imageId )
						? Math.max( 0, Math.floor( o.imageId ) )
						: 0;
				return {
					id: typeof o.id === 'string' ? o.id : '',
					title: typeof o.title === 'string' ? o.title : '',
					tagline: typeof o.tagline === 'string' ? o.tagline : '',
					description:
						typeof o.description === 'string' ? o.description : '',
					imageId: rawId,
					ctaUrl: typeof o.ctaUrl === 'string' ? o.ctaUrl : '',
					ctaLabel:
						typeof o.ctaLabel === 'string' ? o.ctaLabel : '',
					redirect: !! o.redirect,
				};
			}
			return emptyOutcomeRow();
		} )
	);
}

/**
 * Map REST / Claude errors to actionable editor copy.
 *
 * @param {unknown} err Error from apiFetch.
 * @return {string}
 */
function getGenerateQuizErrorMessage( err ) {
	const e = /** @type {{ code?: string, message?: string, data?: { status?: number, stage?: string, retry_after?: number } }} */ (
		err
	);
	const code = e?.code || '';
	const baseMsg = getRestErrorMessage( err );

	if ( code === 'vibe_check_invalid_json' ) {
		return __(
			'Claude returned text we could not parse. Try a simpler prompt.',
			'vibe-check'
		);
	}

	if ( code === 'rate_limited' ) {
		return __(
			'Too many successful generations this hour. Try again later.',
			'vibe-check'
		);
	}

	if ( code === 'vibe_check_invalid_quiz' ) {
		const stage = e?.data?.stage;
		if ( stage === 'questions' ) {
			return __(
				'Claude did not return valid questions. Ensure every answer scores each of your outcome ids, then try again.',
				'vibe-check'
			);
		}
		if ( stage === 'results' ) {
			return __(
				'Claude did not return valid outcomes. Try again or adjust your prompt.',
				'vibe-check'
			);
		}
		if ( stage === 'sanitize' ) {
			return __(
				'Generated data failed validation: answer scores must only reference your outcome ids. Fix ids or regenerate.',
				'vibe-check'
			);
		}
		if ( stage === 'validate' ) {
			return __(
				'The model output did not match the expected shape (counts of results, questions, answers, or scoring keys). Try again or simplify your prompt.',
				'vibe-check'
			);
		}
		return (
			__( 'Validation failed after generation.', 'vibe-check' ) +
			' ' +
			baseMsg
		);
	}

	if ( code === 'vibe_check_no_api_key' || code === 'bad_request' ) {
		return baseMsg;
	}

	if (
		code === 'vibe_check_anthropic_http' ||
		code === 'vibe_check_anthropic_parse' ||
		code === 'vibe_check_empty_response'
	) {
		return baseMsg;
	}

	return baseMsg;
}

/**
 * @param {string} s
 * @return {string}
 */
function sanitizeResultId( s ) {
	return String( s || '' )
		.toLowerCase()
		.replace( /[^a-z0-9-]+/g, '-' )
		.replace( /^-|-$/g, '' );
}

/**
 * @param {object}   props
 * @param {number}   props.imageId
 * @param {function} props.onChange
 */
function OutcomeImageControl( { imageId, onChange } ) {
	const media = useSelect(
		( select ) =>
			imageId > 0 ? select( 'core' ).getMedia( imageId ) : null,
		[ imageId ]
	);
	return (
		<div className="vibe-check-editor-outcome-image">
			<MediaUploadCheck>
				<MediaUpload
					onSelect={ ( m ) => onChange( m.id ) }
					allowedTypes={ [ 'image' ] }
					value={ imageId || undefined }
					render={ ( { open } ) => (
						<>
							<div className="vibe-check-editor-outcome-image-preview">
								{ imageId > 0 && media?.source_url ? (
									<img
										src={ media.source_url }
										alt={
											media.alt_text ||
											__( 'Result image', 'vibe-check' )
										}
										className="vibe-check-editor-outcome-image-img"
									/>
								) : (
									<div
										className="vibe-check-editor-outcome-image-placeholder"
										aria-hidden="true"
									/>
								) }
							</div>
							<div className="vibe-check-editor-outcome-image-actions">
								<Button variant="secondary" onClick={ open }>
									{ imageId
										? __(
												'Replace image',
												'vibe-check'
										  )
										: __(
												'Select image',
												'vibe-check'
										  ) }
								</Button>
								{ imageId > 0 && (
									<Button
										variant="link"
										isDestructive
										onClick={ () => onChange( 0 ) }
									>
										{ __( 'Remove', 'vibe-check' ) }
									</Button>
								) }
							</div>
						</>
					) }
				/>
			</MediaUploadCheck>
		</div>
	);
}

/**
 * @param {object} props
 * @param {number} props.imageId
 */
function OutcomeImageThumb( { imageId } ) {
	const media = useSelect(
		( select ) =>
			imageId > 0 ? select( 'core' ).getMedia( imageId ) : null,
		[ imageId ]
	);
	if ( imageId > 0 && media?.source_url ) {
		return (
			<span className="vibe-check-editor-outcome-thumb-wrap">
				<img
					src={ media.source_url }
					alt=""
					className="vibe-check-editor-outcome-thumb"
				/>
			</span>
		);
	}
	return (
		<span
			className="vibe-check-editor-outcome-thumb-wrap vibe-check-editor-outcome-thumb-empty"
			aria-hidden="true"
		/>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const { quizTitle, quizSubtitle, questions, results } = attributes;
	const blockRef = useRef( null );
	const blockProps = useBlockProps( {
		ref: blockRef,
		className: 'vibe-check-quiz vibe-check-editor',
	} );

	const outcomeRows = ensureOutcomeRows( results );
	const questionRows = Array.isArray( questions ) ? questions : [];

	const [ prompt, setPrompt ] = useState( '' );
	const [ mode, setMode ] = useState( 'from_outcomes' );
	const [ preset, setPreset ] = useState( '' );
	const [ mlpCharacter, setMlpCharacter ] = useState( '' );
	const [ generating, setGenerating ] = useState( false );
	const [ generationStatus, setGenerationStatus ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ generateSuccess, setGenerateSuccess ] = useState( false );
	const [ generateSuccessMessage, setGenerateSuccessMessage ] = useState( '' );
	const [ previewOutcomeIndex, setPreviewOutcomeIndex ] = useState( '0' );

	useEffect( () => {
		const max = outcomeRows.length - 1;
		const pi = Number.parseInt( previewOutcomeIndex, 10 );
		if ( max < 0 ) {
			return;
		}
		if ( Number.isNaN( pi ) || pi < 0 || pi > max ) {
			setPreviewOutcomeIndex( '0' );
		}
	}, [ outcomeRows.length, previewOutcomeIndex ] );

	useEffect( () => {
		if ( ! generateSuccess ) {
			return;
		}
		const id = window.setTimeout( () => {
			const root = blockRef.current;
			if ( ! root ) {
				return;
			}
			const firstQ = root.querySelector( '.vibe-check-editor-question' );
			if ( firstQ instanceof HTMLElement ) {
				firstQ.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
				firstQ.classList.add( 'vibe-check-editor-question--highlight' );
				window.setTimeout( () => {
					firstQ.classList.remove( 'vibe-check-editor-question--highlight' );
				}, 2600 );
			}
		}, 250 );
		return () => window.clearTimeout( id );
	}, [ generateSuccess, questions ] );

	const outcomesReady = outcomeRows.every( ( r ) =>
		String( r.id || '' ).trim()
	);
	const hasQuestions = questionRows.length > 0;

	const previewPi = Number.parseInt( previewOutcomeIndex, 10 );
	const previewRowForMedia = outcomeRows[ previewPi ];
	const previewImageId =
		previewRowForMedia &&
		typeof previewRowForMedia.imageId === 'number' &&
		! Number.isNaN( previewRowForMedia.imageId )
			? previewRowForMedia.imageId
			: 0;
	const previewAttachment = useSelect(
		( select ) =>
			previewImageId > 0
				? select( 'core' ).getMedia( previewImageId )
				: null,
		[ previewImageId ]
	);
	/** @type {0 | 1 | 2} Step index for the authoring progress UI. */
	const authoringStep = outcomesReady
		? hasQuestions
			? 2
			: 1
		: 0;

	useEffect( () => {
		if ( ! generateSuccess ) {
			return;
		}
		const t = window.setTimeout( () => {
			setGenerateSuccess( false );
			setGenerateSuccessMessage( '' );
		}, 5200 );
		return () => window.clearTimeout( t );
	}, [ generateSuccess ] );

	useEffect( () => {
		if ( preset !== 'my-little-pony' ) {
			setMlpCharacter( '' );
		}
	}, [ preset ] );

	/**
	 * @param {number} index
	 * @param {string} field
	 * @param {string|boolean} value
	 */
	function patchResult( index, field, value ) {
		const next = ensureOutcomeRows( results );
		const row = { ...next[ index ] };
		if ( field === 'id' ) {
			row.id = sanitizeResultId( /** @type {string} */ ( value ) );
		} else if ( field === 'title' ) {
			row.title = /** @type {string} */ ( value );
		} else if ( field === 'tagline' ) {
			row.tagline = /** @type {string} */ ( value );
		} else if ( field === 'description' ) {
			row.description = /** @type {string} */ ( value );
		} else if ( field === 'imageId' ) {
			row.imageId =
				typeof value === 'number' && ! Number.isNaN( value )
					? Math.max( 0, Math.floor( value ) )
					: 0;
		} else if ( field === 'ctaUrl' ) {
			row.ctaUrl = /** @type {string} */ ( value );
		} else if ( field === 'ctaLabel' ) {
			row.ctaLabel = /** @type {string} */ ( value );
		} else if ( field === 'redirect' ) {
			row.redirect = !! value;
		}
		next[ index ] = row;
		setAttributes( { results: next } );
	}

	function addOutcomeRow() {
		const next = ensureOutcomeRows( results );
		if ( next.length >= MAX_OUTCOMES ) {
			return;
		}
		next.push( emptyOutcomeRow() );
		setAttributes( { results: next } );
	}

	/**
	 * @param {number} index
	 */
	function removeOutcomeRow( index ) {
		const next = ensureOutcomeRows( results );
		if ( next.length <= MIN_OUTCOMES ) {
			return;
		}
		next.splice( index, 1 );
		setAttributes( { results: next } );
	}

	function applyFandomOutcomeTemplate() {
		const templateDesc = __(
			'Personality: (How they act, what they care about, relationships.)\n\nVoice notes: (Speech patterns, tone, catchphrases—for the AI writer.)',
			'vibe-check'
		);
		setAttributes( {
			results: [
				{
					id: 'character-one',
					title: __( 'Character name', 'vibe-check' ),
					tagline: '',
					description: templateDesc,
					imageId: 0,
					ctaUrl: '',
					ctaLabel: '',
					redirect: false,
				},
				{
					id: 'character-two',
					title: __( 'Character name', 'vibe-check' ),
					tagline: '',
					description: templateDesc,
					imageId: 0,
					ctaUrl: '',
					ctaLabel: '',
					redirect: false,
				},
				{
					id: 'character-three',
					title: __( 'Character name', 'vibe-check' ),
					tagline: '',
					description: templateDesc,
					imageId: 0,
					ctaUrl: '',
					ctaLabel: '',
					redirect: false,
				},
			],
		} );
		setPreviewOutcomeIndex( '0' );
	}

	/**
	 * @param {number} qIndex
	 * @param {string} value
	 */
	function patchQuestionText( qIndex, value ) {
		const next = questionRows.map( ( q, i ) =>
			i === qIndex ? { ...q, text: value } : q
		);
		setAttributes( { questions: next } );
	}

	/**
	 * @param {number} qIndex
	 * @param {number} aIndex
	 * @param {string} value
	 */
	function patchAnswerText( qIndex, aIndex, value ) {
		const next = questionRows.map( ( q, i ) => {
			if ( i !== qIndex ) {
				return q;
			}
			const answers = Array.isArray( q.answers ) ? q.answers : [];
			const newAnswers = answers.map( ( a, j ) =>
				j === aIndex ? { ...a, text: value } : a
			);
			return { ...q, answers: newAnswers };
		} );
		setAttributes( { questions: next } );
	}

	async function onGenerate() {
		setError( null );
		setGenerateSuccess( false );
		setGenerateSuccessMessage( '' );
		setGenerationStatus( '' );
		const trimmed = prompt.trim();
		const res = ensureOutcomeRows( results );

		if ( mode === 'full' && trimmed.length < 10 ) {
			setError(
				__(
					'Describe your quiz in at least 10 characters (topic, tone, audience).',
					'vibe-check'
				)
			);
			return;
		}

		if ( mode === 'from_outcomes' ) {
			if ( trimmed.length < 10 ) {
				setError(
					__(
						'Describe what questions should cover (at least 10 characters).',
						'vibe-check'
					)
				);
				return;
			}
			const missingId = res.some( ( r ) => ! String( r.id || '' ).trim() );
			if ( missingId ) {
				setError(
					__(
						'Give each outcome a unique id (e.g. twilight-sparkle) before generating.',
						'vibe-check'
					)
				);
				return;
			}
		}

		if ( preset === 'my-little-pony' && ! mlpCharacter ) {
			setError(
				__( 'Select a pony character for the My Little Pony style.', 'vibe-check' )
			);
			return;
		}

		const statusMessages = getGenerationStatusMessages( mode );
		setGenerationStatus( statusMessages[0] );
		let statusIndex = 0;
		const statusInterval = window.setInterval( () => {
			statusIndex = Math.min( statusIndex + 1, statusMessages.length - 1 );
			setGenerationStatus( statusMessages[ statusIndex ] );
		}, 7500 );

		setGenerating( true );
		try {
			/** @type {Record<string, unknown>} */
			const data = {
				mode,
				preset,
				prompt: trimmed,
			};
			if ( preset === 'my-little-pony' && mlpCharacter ) {
				data.mlp_character = mlpCharacter;
			}
			if (
				mode === 'from_outcomes' ||
				mode === 'questions' ||
				mode === 'results'
			) {
				data.existing = {
					quizTitle,
					questions,
					results: res,
				};
			}
			const response = await apiFetch( {
				path: '/vibe-check/v1/generate-quiz',
				method: 'POST',
				data,
			} );
			setAttributes( {
				quizTitle: response.quizTitle,
				questions: response.questions,
				results: response.results,
			} );
			setGenerateSuccessMessage(
				mode === 'results'
					? __(
							'Outcomes updated—review on the canvas.',
							'vibe-check'
					  )
					: mode === 'full'
					? __(
							'Quiz generated—review on the canvas.',
							'vibe-check'
					  )
					: __(
							'Questions added—tweak them on the canvas.',
							'vibe-check'
					  )
			);
			setGenerateSuccess( true );
		} catch ( e ) {
			setError( getGenerateQuizErrorMessage( e ) );
		} finally {
			window.clearInterval( statusInterval );
			setGenerationStatus( '' );
			setGenerating( false );
		}
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Quiz', 'vibe-check' ) }
					initialOpen={ true }
				>
					<p>
						{ __(
							'Define outcomes on the block, then generate questions. Edit everything here before publishing.',
							'vibe-check'
						) }
					</p>
				</PanelBody>
				<PanelBody
					title={ __( 'Generate with Claude', 'vibe-check' ) }
					initialOpen={ true }
				>
					<SelectControl { ...WP_SELECT_CONTROL_PROPS }
						label={ __( 'Generation mode', 'vibe-check' ) }
						value={ mode }
						options={ [
							{
								label: __(
									'Questions from my outcomes (recommended)',
									'vibe-check'
								),
								value: 'from_outcomes',
							},
							{
								label: __(
									'Questions only (keep results)',
									'vibe-check'
								),
								value: 'questions',
							},
							{
								label: __( 'Full quiz (new)', 'vibe-check' ),
								value: 'full',
							},
							{
								label: __(
									'Results only (keep questions)',
									'vibe-check'
								),
								value: 'results',
							},
						] }
						onChange={ setMode }
						help={
							mode === 'from_outcomes'
								? __(
										'Uses the outcomes on the canvas (3–6). Claude writes questions and scoring only.',
										'vibe-check'
								  )
								: mode === 'questions'
								? __(
										'Keeps your outcomes; rewrites questions.',
										'vibe-check'
								  )
								: mode === 'full'
								? __(
										'Creates title, questions, and outcomes from your prompt.',
										'vibe-check'
								  )
								: __(
										'Keeps your questions; rewrites outcomes (same result ids).',
										'vibe-check'
								  )
						}
					/>
					<SelectControl { ...WP_SELECT_CONTROL_PROPS }
						label={ __( 'Style preset', 'vibe-check' ) }
						value={ preset }
						options={ [
							{
								label: __( 'None', 'vibe-check' ),
								value: '',
							},
							{
								label: __( 'Naruto', 'vibe-check' ),
								value: 'naruto',
							},
							{
								label: __( 'Tokidoki', 'vibe-check' ),
								value: 'tokidoki',
							},
							{
								label: __( 'My Little Pony', 'vibe-check' ),
								value: 'my-little-pony',
							},
						] }
						onChange={ setPreset }
					/>
					{ preset === 'my-little-pony' && (
						<SelectControl { ...WP_SELECT_CONTROL_PROPS }
							label={ __( 'Pony voice', 'vibe-check' ) }
							help={ __(
								'Result blurbs follow QuizResultWriter rules for this character. Pick the voice that should lead the quiz tone.',
								'vibe-check'
							) }
							value={ mlpCharacter }
							options={ [
								{
									label: __( 'Select…', 'vibe-check' ),
									value: '',
								},
								...MLP_PONY_OPTIONS,
							] }
							onChange={ setMlpCharacter }
						/>
					) }
					<TextareaControl { ...WP_TEXTAREA_CONTROL_PROPS }
						label={
							mode === 'full'
								? __(
										'What should the quiz be about?',
										'vibe-check'
								  )
								: __(
										'Instructions for the AI',
										'vibe-check'
								  )
						}
						help={
							mode === 'from_outcomes'
								? __(
										'Describe tone, difficulty, and what each question should feel like. Your outcomes stay fixed.',
										'vibe-check'
								  )
								: mode === 'full'
								? __(
										'Example: “A lighthearted weekend vibe quiz for coffee lovers, three personality types.”',
										'vibe-check'
								  )
								: __(
										'Optional: extra instructions for this regeneration.',
										'vibe-check'
								  )
						}
						value={ prompt }
						onChange={ setPrompt }
						rows={ 5 }
					/>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ generating && (
						<div
							className="vibe-check-editor-generation-status"
							role="status"
							aria-live="polite"
							aria-busy="true"
						>
							<Spinner />
							<p className="vibe-check-editor-generation-status-text">
								{ generationStatus }
							</p>
						</div>
					) }
					<Button
						variant="primary"
						onClick={ onGenerate }
						disabled={ generating }
						isBusy={ generating }
					>
						{ __( 'Generate', 'vibe-check' ) }
					</Button>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="vibe-check-editor-preview">
					<RichText
						tagName="h2"
						value={ quizTitle }
						onChange={ ( v ) => setAttributes( { quizTitle: v } ) }
						placeholder={ __( 'Quiz title…', 'vibe-check' ) }
					/>
					<TextControl
						{ ...WP_TEXT_CONTROL_PROPS }
						label={ __( 'Subtitle (intro)', 'vibe-check' ) }
						value={ quizSubtitle || '' }
						onChange={ ( v ) =>
							setAttributes( { quizSubtitle: v || '' } )
						}
						placeholder={ __(
							'Answer honestly — your vibe will find its match.',
							'vibe-check'
						) }
						help={ __(
							'Shown under the title on the start screen. Leave empty for the default line.',
							'vibe-check'
						) }
					/>

					{ generating && (
						<div
							className="vibe-check-editor-generation-banner"
							role="status"
							aria-live="polite"
						>
							<Spinner />
							<span className="vibe-check-editor-generation-banner-text">
								{ __(
									'Generating with Claude—see the block sidebar for details.',
									'vibe-check'
								) }
							</span>
						</div>
					) }

					<nav
						className="vibe-check-editor-authoring-steps"
						aria-label={ __( 'Authoring progress', 'vibe-check' ) }
					>
						<ol className="vibe-check-editor-authoring-steps-list">
							<li
								className={
									authoringStep === 0
										? 'is-current'
										: authoringStep > 0
										? 'is-done'
										: ''
								}
							>
								<span className="vibe-check-editor-authoring-step-label">
									{ __( 'Outcomes', 'vibe-check' ) }
								</span>
							</li>
							<li
								aria-hidden="true"
								className="vibe-check-editor-authoring-steps-sep"
							>
								→
							</li>
							<li
								className={
									authoringStep === 1
										? 'is-current'
										: authoringStep > 1
										? 'is-done'
										: ''
								}
							>
								<span className="vibe-check-editor-authoring-step-label">
									{ __( 'Generate', 'vibe-check' ) }
								</span>
							</li>
							<li
								aria-hidden="true"
								className="vibe-check-editor-authoring-steps-sep"
							>
								→
							</li>
							<li
								className={ authoringStep === 2 ? 'is-current' : '' }
							>
								<span className="vibe-check-editor-authoring-step-label">
									{ __( 'Polish', 'vibe-check' ) }
								</span>
							</li>
						</ol>
					</nav>

					{ generateSuccess && (
						<Notice
							className="vibe-check-editor-generate-success"
							status="success"
							isDismissible
							onRemove={ () => {
								setGenerateSuccess( false );
								setGenerateSuccessMessage( '' );
							} }
						>
							{ generateSuccessMessage ||
								__(
									'Generation complete—review on the canvas.',
									'vibe-check'
								) }
						</Notice>
					) }

					<section
						className="vibe-check-editor-section"
						aria-label={ __( 'Outcomes', 'vibe-check' ) }
					>
						<h3 className="vibe-check-editor-section-title">
							{ __( 'Outcomes (what people can get)', 'vibe-check' ) }
						</h3>
						<p className="vibe-check-editor-hint">
							{ __(
								'Set ids, titles, and descriptions (3–6 outcomes). Claude will only add questions and scoring.',
								'vibe-check'
							) }
						</p>
						<div className="vibe-check-editor-outcome-template-actions">
							<Button
								variant="secondary"
								onClick={ addOutcomeRow }
								disabled={ outcomeRows.length >= MAX_OUTCOMES }
							>
								{ __( 'Add outcome', 'vibe-check' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ applyFandomOutcomeTemplate }
							>
								{ __( 'Fandom character template', 'vibe-check' ) }
							</Button>
						</div>
						{ outcomeRows.map( ( row, index ) => (
							<div
								key={ index }
								className="vibe-check-editor-outcome vibe-check-editor-outcome-card"
							>
								<div className="vibe-check-editor-outcome-card-head">
									<OutcomeImageThumb imageId={ row.imageId } />
									<div className="vibe-check-editor-outcome-card-meta">
										<span className="vibe-check-editor-outcome-chip">
											{ row.id?.trim() || '—' }
										</span>
										<p className="vibe-check-editor-outcome-label">
											{ sprintf(
												/* translators: %d: outcome number (1–6) */
												__( 'Outcome %d', 'vibe-check' ),
												index + 1
											) }
										</p>
									</div>
									{ outcomeRows.length > MIN_OUTCOMES && (
										<Button
											className="vibe-check-editor-outcome-remove"
											variant="link"
											isDestructive
											onClick={ () =>
												removeOutcomeRow( index )
											}
										>
											{ __( 'Remove outcome', 'vibe-check' ) }
										</Button>
									) }
								</div>
								<div className="vibe-check-editor-outcome-fields">
									<TextControl { ...WP_TEXT_CONTROL_PROPS }
										label={ __( 'Id (slug)', 'vibe-check' ) }
										value={ row.id }
										onChange={ ( v ) =>
											patchResult( index, 'id', v )
										}
										help={ __(
											'Lowercase letters, numbers, hyphens. Used for scoring.',
											'vibe-check'
										) }
									/>
									<TextControl { ...WP_TEXT_CONTROL_PROPS }
										label={ __( 'Title', 'vibe-check' ) }
										value={ row.title }
										onChange={ ( v ) =>
											patchResult( index, 'title', v )
										}
									/>
									<TextControl { ...WP_TEXT_CONTROL_PROPS }
										label={ __(
											'Tagline (result chip)',
											'vibe-check'
										) }
										value={ row.tagline || '' }
										onChange={ ( v ) =>
											patchResult( index, 'tagline', v )
										}
										help={ __(
											'Short line under the result title on the result card (optional).',
											'vibe-check'
										) }
									/>
									<TextareaControl { ...WP_TEXTAREA_CONTROL_PROPS }
										label={ __( 'Description', 'vibe-check' ) }
										value={ row.description }
										onChange={ ( v ) =>
											patchResult( index, 'description', v )
										}
										rows={ 3 }
									/>
									<p className="vibe-check-editor-outcome-label">
										{ __( 'Result image', 'vibe-check' ) }
									</p>
									<OutcomeImageControl
										imageId={ row.imageId }
										onChange={ ( v ) =>
											patchResult( index, 'imageId', v )
										}
									/>
									<p className="vibe-check-editor-outcome-label vibe-check-editor-outcome-cta-heading">
										{ __(
											'Result screen (after this outcome)',
											'vibe-check'
										) }
									</p>
									<TextControl { ...WP_TEXT_CONTROL_PROPS }
										label={ __(
											'Link URL (optional)',
											'vibe-check'
										) }
										type="url"
										value={ row.ctaUrl }
										onChange={ ( v ) =>
											patchResult( index, 'ctaUrl', v )
										}
										help={ __(
											'Where to send visitors: product page, post, signup, etc. Use https://',
											'vibe-check'
										) }
										placeholder="https://"
									/>
									<TextControl { ...WP_TEXT_CONTROL_PROPS }
										label={ __(
											'Link button label',
											'vibe-check'
										) }
										value={ row.ctaLabel }
										onChange={ ( v ) =>
											patchResult( index, 'ctaLabel', v )
										}
										help={ __(
											'Shown on the button or link. Leave empty to use “Continue”.',
											'vibe-check'
										) }
										placeholder={ __( 'Continue', 'vibe-check' ) }
									/>
									<ToggleControl { ...WP_TOGGLE_CONTROL_PROPS }
										label={ __(
											'Auto-redirect to this link',
											'vibe-check'
										) }
										checked={ row.redirect }
										onChange={ ( v ) =>
											patchResult( index, 'redirect', v )
										}
										help={ __(
											'If enabled, visitors are sent to the URL after a short countdown (they can click “Go now” or use Share first).',
											'vibe-check'
										) }
									/>
								</div>
							</div>
						) ) }
					</section>

					<section
						className="vibe-check-editor-section"
						aria-label={ __( 'Questions', 'vibe-check' ) }
					>
						<h3 className="vibe-check-editor-section-title">
							{ __( 'Questions', 'vibe-check' ) }
						</h3>
						{ questionRows.length === 0 ? (
							<div
								className="vibe-check-editor-empty-questions"
								role="status"
							>
								<span
									className="vibe-check-editor-empty-questions-mark"
									aria-hidden="true"
								/>
								<p className="vibe-check-editor-empty-questions-title">
									{ __( 'No questions yet', 'vibe-check' ) }
								</p>
								<p className="vibe-check-editor-hint vibe-check-editor-empty-questions-hint">
									{ __(
										'Define three outcomes with ids, then open Generate with Claude in the sidebar and run Generate.',
										'vibe-check'
									) }
								</p>
							</div>
						) : (
							questionRows.map( ( q, qIndex ) => (
								<div
									key={ q.id || `q-${ qIndex }` }
									className="vibe-check-editor-question"
								>
									<TextareaControl { ...WP_TEXTAREA_CONTROL_PROPS }
										label={ sprintf(
											/* translators: %d: question number */
											__( 'Question %d', 'vibe-check' ),
											qIndex + 1
										) }
										value={ q.text || '' }
										onChange={ ( v ) =>
											patchQuestionText( qIndex, v )
										}
										rows={ 2 }
									/>
									{ Array.isArray( q.answers ) &&
										q.answers.map( ( a, aIndex ) => (
											<div
												key={
													a.id ||
													`a-${ qIndex }-${ aIndex }`
												}
												className="vibe-check-editor-answer"
											>
												<TextControl { ...WP_TEXT_CONTROL_PROPS }
													label={ sprintf(
														/* translators: 1: question num, 2: answer num */
														__(
															'Answer %1$d · option %2$d',
															'vibe-check'
														),
														qIndex + 1,
														aIndex + 1
													) }
													value={ a.text || '' }
													onChange={ ( v ) =>
														patchAnswerText(
															qIndex,
															aIndex,
															v
														)
													}
												/>
												{ a.scores &&
													typeof a.scores ===
														'object' && (
														<p className="vibe-check-editor-scores-hint">
															{ __(
																'Scores:',
																'vibe-check'
															) }{ ' ' }
															{ Object.entries(
																a.scores
															)
																.map(
																	( [ k, v ] ) =>
																		`${ k }: ${ v }`
																)
																.join( ', ' ) }
														</p>
													) }
											</div>
										) ) }
								</div>
							) )
						) }
					</section>

					<section
						className="vibe-check-editor-section vibe-check-editor-preview-section"
						aria-label={ __( 'Result preview', 'vibe-check' ) }
					>
						<h3 className="vibe-check-editor-section-title">
							{ __( 'Preview result card', 'vibe-check' ) }
						</h3>
						<p className="vibe-check-editor-hint">
							{ __(
								'See how one outcome will look on the front (read-only).',
								'vibe-check'
							) }
						</p>
						<SelectControl { ...WP_SELECT_CONTROL_PROPS }
							label={ __( 'Preview outcome', 'vibe-check' ) }
							value={ previewOutcomeIndex }
							options={ outcomeRows.map( ( r, i ) => ( {
								label:
									r.title?.trim() ||
									sprintf(
										/* translators: %d: outcome number (1–6) */
										__( 'Outcome %d', 'vibe-check' ),
										i + 1
									),
								value: String( i ),
							} ) ) }
							onChange={ setPreviewOutcomeIndex }
						/>
						{ ( () => {
							const pi = Number.parseInt( previewOutcomeIndex, 10 );
							const pr = outcomeRows[ pi ];
							if ( ! pr ) {
								return null;
							}
							const ctaUrlRaw =
								typeof pr.ctaUrl === 'string'
									? pr.ctaUrl.trim()
									: '';
							const safeCta =
								ctaUrlRaw.length > 0 &&
								isSafeHttpUrlPreview( ctaUrlRaw );
							const rawLab =
								typeof pr.ctaLabel === 'string'
									? pr.ctaLabel.trim()
									: '';
							const ctaLabelDisp =
								rawLab || __( 'Continue', 'vibe-check' );
							return (
								<div
									className="vibe-check-editor-result-preview"
									aria-hidden="true"
								>
									<div className="vibe-check-editor-result-preview-card">
										<div className="vibe-check-editor-result-preview-badge">
											{ __( 'Your Result', 'vibe-check' ) }
										</div>
										{ pr.imageId > 0 &&
										previewAttachment?.source_url ? (
											<span className="vibe-check-editor-result-preview-media">
												<img
													src={
														previewAttachment.source_url
													}
													alt=""
													className="vibe-check-editor-result-preview-img"
												/>
											</span>
										) : (
											<span
												className="vibe-check-editor-result-preview-media vibe-check-editor-result-preview-media--empty"
												aria-hidden="true"
											/>
										) }
										<h4 className="vibe-check-editor-result-preview-title">
											{ pr.title?.trim() ||
												__( '(Outcome title)', 'vibe-check' ) }
										</h4>
										<p className="vibe-check-editor-result-preview-desc">
											{ pr.description?.trim() ||
												__(
													'Outcome description appears here.',
													'vibe-check'
												) }
										</p>
										{ safeCta && (
											<p className="vibe-check-editor-result-preview-cta-wrap">
												<span className="vibe-check-editor-result-preview-cta">
													{ ctaLabelDisp }
												</span>
											</p>
										) }
										<div className="vibe-check-editor-result-preview-brand">
											<span>
												{ quizTitle?.replace(
													/<[^>]+>/g,
													''
												) ||
													__(
														'Quiz title',
														'vibe-check'
													) }
											</span>
										</div>
										<div className="vibe-check-editor-result-preview-actions">
											<span className="vibe-check-editor-result-preview-faux-btn vibe-check-editor-result-preview-faux-share">
												{ __(
													'Share Result',
													'vibe-check'
												) }
											</span>
											<span className="vibe-check-editor-result-preview-faux-btn vibe-check-editor-result-preview-faux-save">
												{ __( 'Save Image', 'vibe-check' ) }
											</span>
										</div>
										<p className="vibe-check-editor-result-preview-retake">
											{ __( 'Retake Quiz', 'vibe-check' ) }
										</p>
									</div>
								</div>
							);
						} )() }
					</section>
				</div>
			</div>
		</>
	);
}
