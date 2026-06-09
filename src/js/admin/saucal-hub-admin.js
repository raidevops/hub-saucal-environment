/**
 * Saucal Hub — admin single-page app.
 *
 * React (via @wordpress/element) + PrimeReact. Talks to the saucal-hub/v1 REST
 * API. Lets you register local sites, run a full staging-safety scan, fix
 * individual checks or "make the site safe" in one click, manage the outgoing
 * email guard, and toggle automatic subscription renewals (guarded).
 */

import { createRoot, useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { PrimeReactProvider } from 'primereact/api';
import { Button } from 'primereact/button';
import { Card } from 'primereact/card';
import { Tag } from 'primereact/tag';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { InputSwitch } from 'primereact/inputswitch';
import { Dropdown } from 'primereact/dropdown';
import { Toast } from 'primereact/toast';
import { ProgressSpinner } from 'primereact/progressspinner';
import { Message } from 'primereact/message';
import { Panel } from 'primereact/panel';
import { Divider } from 'primereact/divider';

import 'primereact/resources/themes/lara-light-cyan/theme.css';
import 'primereact/resources/primereact.min.css';
import 'primeicons/primeicons.css';
import './styles.css';

const DATA = window.SaucalHubData || {};

/* -------------------------------------------------------------------------
 * Selected-site <-> URL sync (so the chosen site is reflected/bookmarkable)
 * ---------------------------------------------------------------------- */
function getSiteFromUrl() {
	const params = new URLSearchParams( window.location.search );
	return params.get( 'site' ) || 'self';
}

function setSiteInUrl( id, push ) {
	const url = new URL( window.location.href );
	if ( id && id !== 'self' ) {
		url.searchParams.set( 'site', id );
	} else {
		url.searchParams.delete( 'site' );
	}
	const method = push ? 'pushState' : 'replaceState';
	window.history[ method ]( {}, '', url );
}

/* -------------------------------------------------------------------------
 * REST helper
 * ---------------------------------------------------------------------- */
async function api( path, { method = 'GET', body } = {} ) {
	const res = await fetch( DATA.restUrl + path, {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': DATA.nonce,
		},
		body: body ? JSON.stringify( body ) : undefined,
	} );

	let json = null;
	try {
		json = await res.json();
	} catch ( e ) {
		json = null;
	}

	if ( ! res.ok ) {
		const message =
			( json && json.message ) ||
			__( 'Request failed.', 'saucal-hub' );
		throw new Error( message );
	}
	return json;
}

/* -------------------------------------------------------------------------
 * Status → PrimeReact tag presentation
 * ---------------------------------------------------------------------- */
function statusMeta( status ) {
	switch ( status ) {
		case 'safe':
			return { severity: 'success', label: __( 'Safe', 'saucal-hub' ), icon: 'pi pi-check' };
		case 'unsafe':
			return { severity: 'danger', label: __( 'Unsafe', 'saucal-hub' ), icon: 'pi pi-times' };
		case 'warning':
			return { severity: 'warning', label: __( 'Warning', 'saucal-hub' ), icon: 'pi pi-exclamation-triangle' };
		default:
			return { severity: 'info', label: __( 'N/A', 'saucal-hub' ), icon: 'pi pi-minus' };
	}
}

const GROUP_LABELS = {
	environment: __( 'Environment', 'saucal-hub' ),
	subscriptions: __( 'Subscriptions', 'saucal-hub' ),
	payments: __( 'Payments', 'saucal-hub' ),
	email: __( 'Email', 'saucal-hub' ),
	general: __( 'General', 'saucal-hub' ),
};

/* -------------------------------------------------------------------------
 * Single check row
 * ---------------------------------------------------------------------- */
function CheckRow( { check, busy, onFix, readOnly } ) {
	const [ open, setOpen ] = useState( false );
	const result = check.result || {};
	const meta = statusMeta( result.status );
	const canFix =
		! readOnly &&
		check.fixable &&
		check.applicable &&
		( result.status === 'unsafe' || result.status === 'warning' );
	const details = result.details || {};
	const hasDetails = details && Object.keys( details ).length > 0;

	return (
		<div className="sh-check-row">
			<div className="sh-check-status">
				<Tag severity={ meta.severity } value={ meta.label } icon={ meta.icon } />
			</div>
			<div className="sh-check-body">
				<strong>{ check.label }</strong>
				<div className="sh-check-message">{ result.message }</div>
				{ details.remediation && (
					<code className="sh-remediation">{ details.remediation }</code>
				) }
				{ check.description && (
					<div className="sh-check-desc">{ check.description }</div>
				) }
				{ hasDetails && (
					<>
						<button type="button" className="sh-linkbtn" onClick={ () => setOpen( ! open ) }>
							<i className={ open ? 'pi pi-chevron-down' : 'pi pi-chevron-right' } />{ ' ' }
							{ open ? __( 'Hide technical detail', 'saucal-hub' ) : __( 'Technical detail', 'saucal-hub' ) }
						</button>
						{ open && <pre className="sh-json">{ JSON.stringify( details, null, 2 ) }</pre> }
					</>
				) }
			</div>
			<div className="sh-check-action">
				{ canFix && (
					<Button
						label={ __( 'Fix', 'saucal-hub' ) }
						icon="pi pi-wrench"
						size="small"
						loading={ busy === check.id }
						onClick={ () => onFix( check.id ) }
					/>
				) }
				{ ! check.fixable && check.applicable && result.status !== 'safe' && (
					<Tag severity="secondary" value={ __( 'Manual', 'saucal-hub' ) } />
				) }
			</div>
		</div>
	);
}

/* -------------------------------------------------------------------------
 * Add-site dialog
 * ---------------------------------------------------------------------- */
function AddSiteDialog( { visible, onHide, onAdded, toast } ) {
	const [ label, setLabel ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ path, setPath ] = useState( '' );
	const [ dbName, setDbName ] = useState( '' );
	const [ tablePrefix, setTablePrefix ] = useState( '' );
	const [ detected, setDetected ] = useState( [] );
	const [ picked, setPicked ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	// Auto-discover sites when the dialog opens.
	useEffect( () => {
		if ( ! visible ) {
			return;
		}
		( async () => {
			try {
				const res = await api( '/discover' );
				setDetected( ( res.sites || [] ).filter( ( s ) => ! s.is_current ) );
			} catch ( e ) {
				// Discovery is best-effort; manual entry still works.
			}
		} )();
	}, [ visible ] );

	const applyDetected = ( site ) => {
		setPicked( site.path );
		setLabel( site.label || '' );
		setUrl( site.url || '' );
		setPath( site.path || '' );
		setDbName( site.db_name || '' );
		setTablePrefix( site.table_prefix || '' );
	};

	const submit = async () => {
		setSaving( true );
		try {
			const res = await api( '/sites', {
				method: 'POST',
				body: { label, url, path, db_name: dbName, table_prefix: tablePrefix },
			} );
			toast.current?.show( {
				severity: 'success',
				summary: __( 'Site added', 'saucal-hub' ),
				detail: res.site.label,
			} );
			setLabel( '' );
			setUrl( '' );
			setPath( '' );
			setDbName( '' );
			setTablePrefix( '' );
			setPicked( null );
			onAdded( res.sites );
			onHide();
		} catch ( e ) {
			toast.current?.show( { severity: 'error', summary: __( 'Error', 'saucal-hub' ), detail: e.message } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Dialog
			header={ __( 'Add a site', 'saucal-hub' ) }
			visible={ visible }
			style={ { width: '460px' } }
			onHide={ onHide }
		>
			<div className="sh-form">
				{ detected.length > 0 && (
					<>
						<label>{ __( 'Detected sites', 'saucal-hub' ) }</label>
						<Dropdown
							value={ picked }
							onChange={ ( e ) => applyDetected( detected.find( ( s ) => s.path === e.value ) ) }
							options={ detected.map( ( s ) => ( {
								label: `${ s.label } — ${ s.host || s.path }`,
								value: s.path,
							} ) ) }
							placeholder={ __( 'Pick a detected site, or enter manually below', 'saucal-hub' ) }
							filter
						/>
						<small className="sh-muted">{ __( 'Auto-discovered from this host. Selecting one fills in the fields below.', 'saucal-hub' ) }</small>
					</>
				) }

				<label>{ __( 'Label', 'saucal-hub' ) }</label>
				<InputText value={ label } onChange={ ( e ) => setLabel( e.target.value ) } placeholder="Talkbox Mom (staging)" />

				<label>{ __( 'Site URL', 'saucal-hub' ) }</label>
				<InputText value={ url } onChange={ ( e ) => setUrl( e.target.value ) } placeholder="https://talkboxmom.docker.local" />

				<label>{ __( 'Container path', 'saucal-hub' ) }</label>
				<InputText value={ path } onChange={ ( e ) => setPath( e.target.value ) } placeholder="/var/www/talkboxmom/ngrok" />

				<label>{ __( 'Database name', 'saucal-hub' ) }</label>
				<InputText value={ dbName } onChange={ ( e ) => setDbName( e.target.value ) } placeholder="talkboxmom_docker_local_ngrok" />

				<label>{ __( 'Table prefix', 'saucal-hub' ) }</label>
				<InputText value={ tablePrefix } onChange={ ( e ) => setTablePrefix( e.target.value ) } placeholder="wp_" />

				<div className="sh-form-actions">
					<Button label={ __( 'Cancel', 'saucal-hub' ) } severity="secondary" text onClick={ onHide } />
					<Button label={ __( 'Add site', 'saucal-hub' ) } icon="pi pi-plus" loading={ saving } disabled={ ! url } onClick={ submit } />
				</div>
			</div>
		</Dialog>
	);
}

/* -------------------------------------------------------------------------
 * Email guard panel
 * ---------------------------------------------------------------------- */
function EmailGuardPanel( { guard, onChange, toast } ) {
	const [ enabled, setEnabled ] = useState( false );
	const [ domains, setDomains ] = useState( 'saucal.com' );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( guard ) {
			setEnabled( !! guard.enabled );
			setDomains( ( guard.allowed_domains || [] ).join( ', ' ) );
		}
	}, [ guard ] );

	const save = async ( nextEnabled ) => {
		setSaving( true );
		try {
			const stored = await api( '/email-guard', {
				method: 'POST',
				body: {
					enabled: nextEnabled,
					allowed_domains: domains,
					mode: 'block',
				},
			} );
			setEnabled( !! stored.enabled );
			toast.current?.show( {
				severity: 'success',
				summary: __( 'Email guard updated', 'saucal-hub' ),
				detail: stored.enabled
					? __( 'Outgoing mail restricted.', 'saucal-hub' )
					: __( 'Guard disabled.', 'saucal-hub' ),
			} );
			onChange();
		} catch ( e ) {
			toast.current?.show( { severity: 'error', summary: __( 'Error', 'saucal-hub' ), detail: e.message } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Panel header={ __( 'Outgoing email guard', 'saucal-hub' ) } toggleable>
			<p className="sh-muted">
				{ __( 'When ON, the site can only send email to the domains below. Everything else is blocked — safe to test renewals without emailing real customers.', 'saucal-hub' ) }
			</p>
			<div className="sh-inline">
				<InputSwitch checked={ enabled } onChange={ ( e ) => save( e.value ) } disabled={ saving } />
				<span>{ enabled ? __( 'Enabled', 'saucal-hub' ) : __( 'Disabled', 'saucal-hub' ) }</span>
			</div>
			<label className="sh-label-top">{ __( 'Allowed domains (comma separated)', 'saucal-hub' ) }</label>
			<div className="sh-inline">
				<InputText value={ domains } onChange={ ( e ) => setDomains( e.target.value ) } style={ { flex: 1 } } />
				<Button label={ __( 'Save', 'saucal-hub' ) } icon="pi pi-save" loading={ saving } onClick={ () => save( enabled ) } />
			</div>
		</Panel>
	);
}

/* -------------------------------------------------------------------------
 * Subscriptions automatic-payments toggle
 * ---------------------------------------------------------------------- */
function SubscriptionsPanel( { scan, onChange, toast } ) {
	const [ saving, setSaving ] = useState( false );

	// Find the relevant check to reflect current state.
	const check = ( scan?.checks || [] ).find( ( c ) => c.id === 'subscriptions_auto_payments_off' );
	const applicable = check && check.applicable;
	// "automatic ON" == option is NOT 'yes' == the off-check is unsafe.
	const automaticOn = check && check.result && check.result.status !== 'safe';

	const toggle = async ( nextOn ) => {
		setSaving( true );
		try {
			const res = await api( '/subscriptions/automatic', {
				method: 'POST',
				body: { enabled: nextOn },
			} );
			toast.current?.show( {
				severity: 'success',
				summary: __( 'Subscriptions', 'saucal-hub' ),
				detail: res.message,
			} );
			onChange();
		} catch ( e ) {
			toast.current?.show( { severity: 'warn', summary: __( 'Blocked', 'saucal-hub' ), detail: e.message, life: 6000 } );
		} finally {
			setSaving( false );
		}
	};

	if ( ! applicable ) {
		return (
			<Panel header={ __( 'Automatic subscription renewals', 'saucal-hub' ) } toggleable collapsed>
				<Message severity="info" text={ __( 'WooCommerce Subscriptions is not active on this site.', 'saucal-hub' ) } />
			</Panel>
		);
	}

	return (
		<Panel header={ __( 'Automatic subscription renewals', 'saucal-hub' ) } toggleable>
			<p className="sh-muted">
				{ __( 'Disabled = all renewals are manual (safe). Re-enabling is only allowed once the email guard is ON, so renewals can only reach allowed domains.', 'saucal-hub' ) }
			</p>
			<div className="sh-inline">
				<InputSwitch checked={ !! automaticOn } onChange={ ( e ) => toggle( e.value ) } disabled={ saving } />
				<span>
					{ automaticOn
						? __( 'Automatic renewals ON', 'saucal-hub' )
						: __( 'Automatic renewals OFF (manual)', 'saucal-hub' ) }
				</span>
			</div>
		</Panel>
	);
}

/* -------------------------------------------------------------------------
 * Copyable command block
 * ---------------------------------------------------------------------- */
function CommandBlock( { commands, toast } ) {
	const copy = ( cmd ) => {
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( cmd );
			toast?.current?.show( { severity: 'info', summary: __( 'Copied', 'saucal-hub' ), life: 1500 } );
		}
	};
	return (
		<div className="sh-commands">
			<p className="sh-muted">
				{ __( 'Run from the docker-env root to scan / make this site safe (results appear here after refresh):', 'saucal-hub' ) }
			</p>
			{ [ [ 'scan', commands.scan ], [ 'make_safe', commands.make_safe ] ].map( ( [ key, cmd ] ) =>
				cmd ? (
					<div className="sh-inline" key={ key }>
						<code className="sh-cmd">{ cmd }</code>
						<Button icon="pi pi-copy" size="small" text onClick={ () => copy( cmd ) } />
					</div>
				) : null
			) }
			<p className="sh-muted">
				{ __( 'Or simply: ', 'saucal-hub' ) }
				<code className="sh-cmd">bin/saucal-hub.sh scan --all</code>
			</p>
		</div>
	);
}

/* -------------------------------------------------------------------------
 * Activity log dialog (before/after audit trail)
 * ---------------------------------------------------------------------- */
function ActivityDialog( { visible, onHide, toast } ) {
	const [ events, setEvents ] = useState( [] );
	const [ loading, setLoading ] = useState( false );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const res = await api( '/activity?limit=100' );
			setEvents( res.events || [] );
		} catch ( e ) {
			toast?.current?.show( { severity: 'error', summary: __( 'Error', 'saucal-hub' ), detail: e.message } );
		} finally {
			setLoading( false );
		}
	}, [ toast ] );

	useEffect( () => {
		if ( visible ) {
			load();
		}
	}, [ visible, load ] );

	const clear = async () => {
		await api( '/activity', { method: 'DELETE' } );
		setEvents( [] );
	};

	return (
		<Dialog
			header={ __( 'Activity log (before → after)', 'saucal-hub' ) }
			visible={ visible }
			style={ { width: '780px', maxWidth: '95vw' } }
			onHide={ onHide }
		>
			<div className="sh-inline" style={ { justifyContent: 'flex-end' } }>
				<Button label={ __( 'Refresh', 'saucal-hub' ) } icon="pi pi-refresh" text size="small" onClick={ load } loading={ loading } />
				<Button label={ __( 'Clear', 'saucal-hub' ) } icon="pi pi-trash" text size="small" severity="danger" onClick={ clear } />
			</div>

			{ events.length === 0 && (
				<Message severity="info" style={ { width: '100%' } } text={ __( 'No activity recorded yet. Apply a fix to see before/after detail here.', 'saucal-hub' ) } />
			) }

			{ events.map( ( ev, i ) => {
				const meta = statusMeta( ev.level === 'success' ? 'safe' : ev.level === 'error' ? 'unsafe' : ev.level === 'warning' ? 'warning' : 'na' );
				const when = ev.time ? new Date( ev.time * 1000 ).toLocaleString() : '';
				return (
					<div className="sh-activity" key={ i }>
						<div className="sh-activity-head">
							<Tag severity={ meta.severity } value={ ev.action } />
							<strong>{ ev.check || 'general' }</strong>
							<span className="sh-muted">{ ev.site }</span>
							<span className="sh-spacer" />
							<span className="sh-muted">{ when }</span>
						</div>
						<div className="sh-check-message">{ ev.message }</div>
						{ ( ev.before || ev.after ) && (
							<div className="sh-beforeafter">
								<div>
									<span className="sh-tagmini sh-before">{ __( 'before', 'saucal-hub' ) }</span>
									<pre className="sh-json">{ JSON.stringify( ev.before, null, 2 ) }</pre>
								</div>
								<div>
									<span className="sh-tagmini sh-after">{ __( 'after', 'saucal-hub' ) }</span>
									<pre className="sh-json">{ JSON.stringify( ev.after, null, 2 ) }</pre>
								</div>
							</div>
						) }
						{ ev.changed && (
							<details className="sh-changed">
								<summary>{ __( 'changed', 'saucal-hub' ) }</summary>
								<pre className="sh-json">{ JSON.stringify( ev.changed, null, 2 ) }</pre>
							</details>
						) }
					</div>
				);
			} ) }
		</Dialog>
	);
}

/* -------------------------------------------------------------------------
 * Main app
 * ---------------------------------------------------------------------- */
function App() {
	const toast = useRef( null );
	const [ sites, setSites ] = useState( [] );
	const [ siteId, setSiteId ] = useState( getSiteFromUrl );
	const [ scan, setScan ] = useState( null );
	const [ commands, setCommands ] = useState( null );
	const [ guard, setGuard ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ scanning, setScanning ] = useState( false );
	const [ fixing, setFixing ] = useState( null );
	const [ fixingAll, setFixingAll ] = useState( false );
	const [ showAdd, setShowAdd ] = useState( false );
	const [ showActivity, setShowActivity ] = useState( false );

	const selectedSite = sites.find( ( s ) => s.id === siteId );
	const isSelf = siteId === 'self';

	const loadSites = useCallback( async () => {
		const res = await api( '/sites' );
		setSites( res.sites );
	}, [] );

	const runScan = useCallback( async () => {
		setScanning( true );
		try {
			// Live DB-first scan for ANY site (self = local source, others = cross-DB).
			const res = await api( '/scan?site=' + encodeURIComponent( siteId ) );
			setScan( res );
			// For remote sites, also fetch the equivalent CLI commands (the authoritative path).
			if ( ! isSelf ) {
				try {
					const rep = await api( '/sites/' + siteId + '/report' );
					setCommands( rep.commands || null );
				} catch ( e2 ) {
					setCommands( null );
				}
			} else {
				setCommands( null );
			}
		} catch ( e ) {
			toast.current?.show( { severity: 'error', summary: __( 'Scan failed', 'saucal-hub' ), detail: e.message } );
		} finally {
			setScanning( false );
		}
	}, [ siteId, isSelf ] );

	const loadGuard = useCallback( async () => {
		const res = await api( '/email-guard' );
		setGuard( res );
	}, [] );

	useEffect( () => {
		( async () => {
			setLoading( true );
			try {
				await Promise.all( [ loadSites(), loadGuard() ] );
				await runScan();
			} finally {
				setLoading( false );
			}
		} )();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		runScan();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ siteId ] );

	// Keep the selected site in the URL, and react to browser back/forward.
	const changeSite = useCallback( ( id ) => {
		setSiteId( id );
		setSiteInUrl( id, true );
	}, [] );

	useEffect( () => {
		const onPop = () => setSiteId( getSiteFromUrl() );
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	const fixOne = async ( id ) => {
		setFixing( id );
		try {
			const res = await api( '/fix', { method: 'POST', body: { check: id, site: siteId } } );
			toast.current?.show( { severity: 'success', summary: __( 'Fixed', 'saucal-hub' ), detail: res.message } );
			await runScan();
			if ( isSelf ) {
				await loadGuard();
			}
		} catch ( e ) {
			toast.current?.show( { severity: 'error', summary: __( 'Fix failed', 'saucal-hub' ), detail: e.message } );
		} finally {
			setFixing( null );
		}
	};

	const fixAll = async () => {
		setFixingAll( true );
		try {
			const res = await api( '/fix-all', { method: 'POST', body: { site: siteId } } );
			const count = Object.keys( res.applied || {} ).length;
			toast.current?.show( {
				severity: 'success',
				summary: __( 'Make site safe', 'saucal-hub' ),
				detail: count
					? `${ count } ${ __( 'fixes applied.', 'saucal-hub' ) }`
					: __( 'Nothing to fix — already safe.', 'saucal-hub' ),
			} );
			if ( res.scan ) {
				setScan( res.scan );
			}
			if ( isSelf ) {
				await loadGuard();
			}
		} catch ( e ) {
			toast.current?.show( { severity: 'error', summary: __( 'Error', 'saucal-hub' ), detail: e.message } );
		} finally {
			setFixingAll( false );
		}
	};

	if ( loading ) {
		return (
			<div className="sh-center">
				<ProgressSpinner />
			</div>
		);
	}

	const summary = scan?.summary || {};
	const checks = scan?.checks || [];

	// Group checks for the checklist.
	const groups = {};
	checks.forEach( ( c ) => {
		( groups[ c.group ] = groups[ c.group ] || [] ).push( c );
	} );

	return (
		<div className="sh-app">
			<Toast ref={ toast } />

			<header className="sh-header">
				<div className="sh-title">
					<i className="pi pi-shield" />
					<h1>{ __( 'Saucal Hub', 'saucal-hub' ) }</h1>
				</div>
				<div className="sh-header-actions">
					<Dropdown
						value={ siteId }
						onChange={ ( e ) => changeSite( e.value ) }
						options={ sites.map( ( s ) => ( {
							label: ( s.url || s.host || s.label ) + ( s.is_self ? ' ' + __( '(this site)', 'saucal-hub' ) : '' ),
							value: s.id,
						} ) ) }
						placeholder={ __( 'Select a site', 'saucal-hub' ) }
					/>
					<Button label={ __( 'Activity', 'saucal-hub' ) } icon="pi pi-history" text onClick={ () => setShowActivity( true ) } />
					<Button label={ __( 'Add site', 'saucal-hub' ) } icon="pi pi-plus" outlined onClick={ () => setShowAdd( true ) } />
				</div>
			</header>

			{ selectedSite && (
				<div className="sh-site-meta">
					<a href={ selectedSite.url || ( scan && scan.host ) } target="_blank" rel="noreferrer">{ selectedSite.url || scan?.host }</a>
					{ ! isSelf && <Tag severity="info" icon="pi pi-database" value={ __( 'Scanned over shared DB', 'saucal-hub' ) } /> }
					{ scan && (
						scan.safe_host
							? <Tag severity="success" icon="pi pi-check" value={ __( 'Local/staging host', 'saucal-hub' ) } />
							: <Tag severity="danger" icon="pi pi-exclamation-triangle" value={ __( 'Host does not look like a clone — fixes are blocked', 'saucal-hub' ) } />
					) }
				</div>
			) }

			<section className="sh-summary">
				<Tag severity="success" value={ `${ __( 'Safe', 'saucal-hub' ) }: ${ summary.safe || 0 }` } />
				<Tag severity="danger" value={ `${ __( 'Unsafe', 'saucal-hub' ) }: ${ summary.unsafe || 0 }` } />
				<Tag severity="warning" value={ `${ __( 'Warnings', 'saucal-hub' ) }: ${ summary.warning || 0 }` } />
				<Tag severity="info" value={ `${ __( 'N/A', 'saucal-hub' ) }: ${ summary.na || 0 }` } />
				<div className="sh-spacer" />
				<Button label={ __( 'Run full scan', 'saucal-hub' ) } icon="pi pi-search" outlined loading={ scanning } onClick={ runScan } />
				<Button
					label={ __( 'Make site safe', 'saucal-hub' ) }
					icon="pi pi-verified"
					severity="danger"
					loading={ fixingAll }
					disabled={ ! scan?.safe_host }
					onClick={ fixAll }
				/>
			</section>

			<div className="sh-columns">
				<div className="sh-col-main">
					{ Object.keys( groups ).map( ( g ) => (
						<Card key={ g } title={ GROUP_LABELS[ g ] || g } className="sh-group">
							{ groups[ g ].map( ( c ) => (
								<CheckRow key={ c.id } check={ c } busy={ fixing } onFix={ fixOne } />
							) ) }
						</Card>
					) ) }
				</div>
				<div className="sh-col-side">
					{ isSelf ? (
						<>
							<EmailGuardPanel guard={ guard } onChange={ runScan } toast={ toast } />
							<Divider />
							<SubscriptionsPanel scan={ scan } onChange={ runScan } toast={ toast } />
						</>
					) : (
						<Panel header={ __( 'Authoritative path (wp-cli)', 'saucal-hub' ) }>
							<p className="sh-muted">
								{ __( 'Scans/fixes above run over the shared database. For full in-context accuracy (e.g. WP_ENVIRONMENT_TYPE set via env var) run wp-cli:', 'saucal-hub' ) }
							</p>
							{ commands && <CommandBlock commands={ commands } toast={ toast } /> }
						</Panel>
					) }
				</div>
			</div>

			<AddSiteDialog
				visible={ showAdd }
				onHide={ () => setShowAdd( false ) }
				onAdded={ ( s ) => setSites( s ) }
				toast={ toast }
			/>

			<ActivityDialog
				visible={ showActivity }
				onHide={ () => setShowActivity( false ) }
				toast={ toast }
			/>
		</div>
	);
}

const el = document.getElementById( 'saucal-hub-app' );
if ( el ) {
	createRoot( el ).render(
		<PrimeReactProvider>
			<App />
		</PrimeReactProvider>
	);
}
