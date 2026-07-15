import { useState, useEffect, useCallback, useRef, useMemo, Fragment } from '@wordpress/element';
import { spsg } from '../lib/api';
import Toast from '../components/Toast';

const WIZARD_STEPS = [ 'Teams & Season', 'Rinks & Times', 'Review & Generate' ];

const DAYS = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
const DL = {monday:'Mon',tuesday:'Tue',wednesday:'Wed',thursday:'Thu',friday:'Fri',saturday:'Sat',sunday:'Sun'};

// Fix #14/#15: default to double_round_robin with sensible games_per_team
const blank = () => ({
	name:'', start_date:'', end_date:'',
	playing_days:['friday','sunday'], games_per_team:10,
	match_length:60, matchup_style:'double_round_robin',
	divisions:[], venues:[], time_slots:{},
	blackout_dates:'', venue_blackout_dates:{}, venue_timeslots:{},
	generic_teams:{enabled:false,per_division:0,prefix:'Team'},
	advanced:{b2b_pairs:[],overlap_pairs:[],inter_division:{},venue_prefs:{}},
});
const mkId = p => `${p}_${Date.now()}_${Math.random().toString(36).slice(2,6)}`;

function Tbd({name}) {
	if (!name) return null;
	return typeof name === 'string' && name.startsWith('TBD')
		? <><span className="splm-badge splm-badge--warning">TBD</span> {name}</>
		: name;
}

// Fix #10/#17: capacity uses per-day slot counts, not average
function Cap({cfg}) {
	const tt = cfg.divisions.reduce((s,d) => s+d.teams.length, 0);
	const need = Math.ceil(tt * cfg.games_per_team / 2);
	if (!cfg.start_date || !cfg.end_date || !cfg.venues.length) return null;
	const s = new Date(cfg.start_date), e = new Date(cfg.end_date);
	let avail = 0;
	for (let d = new Date(s); d <= e; d.setDate(d.getDate()+1)) {
		const day = DAYS[(d.getDay()+6)%7];
		if (cfg.playing_days.includes(day)) {
			const ds = d.toISOString().split('T')[0];
			// Gap #13: subtract venues blacked out on this date
			const openVenues = cfg.venues.filter(vid => !(cfg.venue_blackout_dates[vid]||[]).includes(ds)).length;
			avail += (cfg.time_slots[day]||[]).length * openVenues;
		}
	}
	const pct = avail ? Math.round(need/avail*100) : 999;
	const col = pct>95||need>avail ? 'var(--splm-danger)' : pct>80 ? 'var(--splm-warn-amber)' : 'var(--splm-success)';
	return (
		<div className="splm-card" style={{marginTop:'1rem'}}>
			<strong>Capacity:</strong> {need} needed / {avail} available ({pct}%)
			<div style={{height:8,background:'var(--splm-border)',borderRadius:4,marginTop:6}}>
				<div style={{width:`${Math.min(pct,100)}%`,height:'100%',background:col,borderRadius:4}}/>
			</div>
		</div>
	);
}

// Fix #3: compute per-team stats from games array
function computeStats(games) {
	const stats = {};
	(games||[]).forEach(g => {
		if (g.home) { stats[g.home] = stats[g.home]||{total:0,home:0,away:0}; stats[g.home].total++; stats[g.home].home++; }
		if (g.away) { stats[g.away] = stats[g.away]||{total:0,home:0,away:0}; stats[g.away].total++; stats[g.away].away++; }
	});
	return stats;
}

function VenueSel({venues,selected,onLoad,onChange}) {
	const loadedRef = useRef(false);
	useEffect(() => {
		if (!loadedRef.current && !venues.length) { loadedRef.current = true; onLoad(); }
	}, [venues.length, onLoad]);
	const tog = id => onChange(selected.includes(id) ? selected.filter(v=>v!==id) : [...selected,id]);
	return (
		<div>
			<label>Venues ({selected.length} selected)</label>
			{!venues.length && <p className="splm-loading">Loading…</p>}
			<div className="splm-checkbox-grid">
				{venues.map(v=><label key={v.id} className="splm-checkbox"><input type="checkbox" checked={selected.includes(v.id)} onChange={()=>tog(v.id)}/> {v.name}</label>)}
			</div>
		</div>
	);
}

function SLLoad({items,onLoad,value,onChange,label}) {
	const loadedRef = useRef(false);
	useEffect(() => {
		if (!loadedRef.current && !items.length) { loadedRef.current = true; onLoad(); }
	}, [items.length, onLoad]);
	return (
		<select className="splm-select" aria-label={label} value={value} onChange={e=>onChange(e.target.value)}>
			<option value="">Select…</option>
			{items.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}
		</select>
	);
}

export default function ScheduleGenerator() {
	const [step,setStep] = useState(0);
	const [configs,setConfigs] = useState([]);
	const [cfg,setCfg] = useState(blank());
	const [configId,setConfigId] = useState(null);
	const [loading,setLoading] = useState(false);
	const [error,setError] = useState('');
	const [spL,setSpL] = useState([]);
	const [spV,setSpV] = useState([]);
	const [spS,setSpS] = useState([]);
	const [importLg,setImportLg] = useState('');
	const [cfgSearch,setCfgSearch] = useState(''); // Gap #5: search filter
	const [cfgSort,setCfgSort] = useState('updated'); // #5: sort field
	const [historyId,setHistoryId] = useState(null); // Gap #11: change history
	const [historyData,setHistoryData] = useState([]);
	const [presets,setPresets] = useState(null); // #1: presets
	const [xlsxStyle,setXlsxStyle] = useState('detailed'); // #9: XLSX style
	const [pubOpts,setPubOpts] = useState({conflict_resolution:'skip',event_status:'publish',dry_run:false}); // #10
	const [importPreview,setImportPreview] = useState(null); // #2: import preview
	const [distSettings,setDistSettings] = useState(null); // admin distribution settings
	const [csvParsed,setCsvParsed] = useState(null); // venue CSV parse result
	const [csvMapping,setCsvMapping] = useState({}); // venue CSV mapping
	const csvRef = useRef(null);
	const [validation,setValidation] = useState(null);
	const [generating,setGenerating] = useState(false);
	const [schedule,setSchedule] = useState(null);
	const [divF,setDivF] = useState('');
	const [previewFilters,setPreviewFilters] = useState({}); // #8: team/venue/date filters
	const [pubProg,setPubProg] = useState(null);
	const [publishing,setPublishing] = useState(false);
	const [pubSeason,setPubSeason] = useState('');
	const [pubLeague,setPubLeague] = useState('');
	// TBD replacement state
	const [placeholders,setPlaceholders] = useState([]);
	const [replaceMap,setReplaceMap] = useState({});
	const [spTeams,setSpTeams] = useState([]);
	// Import file ref
	const importRef = useRef(null);
	const tbdRef = useRef(0);
	const [toast,setToast] = useState(null); // UX-7/in-app feedback {message,type}
	const [presetOpen,setPresetOpen] = useState(false); // UX-6
	const presetRef = useRef(null);

	const loadConfigs = useCallback(() => {
		setLoading(true);
		spsg.listConfigs().then(setConfigs).catch(()=>setError('Failed to load configs')).finally(()=>setLoading(false));
	}, []);

	useEffect(() => {
		loadConfigs();
		spsg.listPresets().then(setPresets).catch(()=>{});
		spsg.getDistributionSettings().then(setDistSettings).catch(()=>{});
	}, []);

	// UX-6: dismiss the preset menu on outside click / Escape.
	useEffect(() => {
		if (!presetOpen) return undefined;
		const onDown = (e) => { if (presetRef.current && !presetRef.current.contains(e.target)) setPresetOpen(false); };
		const onKey = (e) => { if (e.key === 'Escape') setPresetOpen(false); };
		document.addEventListener('mousedown', onDown);
		document.addEventListener('keydown', onKey);
		return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey); };
	}, [presetOpen]);

	const up = patch => {
		setCfg(p => typeof patch === 'function' ? patch(p) : ({...p,...patch}));
		// #7: clear stale validation whenever config changes
		setValidation(null);
	};
	const togDay = day => up(prev => ({
		playing_days: prev.playing_days.includes(day)
			? prev.playing_days.filter(d => d !== day)
			: [...prev.playing_days, day]
	}));

	// Gap #1/#2/#16: only save if config has meaningful content
	const hasContent = () => cfg.name.trim() || cfg.divisions.some(d => d.teams.length > 0);

	// UX-4: per-step completion. Forward navigation is gated on the prior step
	// being valid so a user can't reach Generate with an unconfigured wizard.
	const stepComplete = (n) => {
		if (n === 1) return !!cfg.start_date && !!cfg.end_date && cfg.divisions.some(d => d.teams.length >= 2);
		if (n === 2) return cfg.venues.length > 0 && cfg.playing_days.some(d => (cfg.time_slots[d]||[]).length > 0);
		return true;
	};
	// A step is reachable if it's the current/earlier step, or every step before
	// it is complete.
	const canReach = (target) => {
		if (target <= step) return true;
		for (let s = 1; s < target; s++) { if (!stepComplete(s)) return false; }
		return true;
	};

	const save = async () => {
		if (configId) { await spsg.updateConfig(configId,cfg); return configId; }
		const r = await spsg.createConfig(cfg);
		setConfigId(r.id);
		return r.id;
	};

	// Save on step transitions, but skip if config is empty and unsaved, or same step (#17)
	const go = async t => {
		// UX-4: block forward navigation past an incomplete prior step.
		if (t > step && !canReach(t)) {
			setError('Complete the current step before continuing.');
			return;
		}
		if (t !== step && step >= 1 && (configId || hasContent())) {
			try { await save(); } catch { setError('Failed to save'); return; }
		}
		setError('');
		if (t === 0) loadConfigs(); // refresh list when returning to launchpad
		setStep(t);
	};

	// Fix #14: import shows dropdown immediately on first click
	const doImport = async () => {
		if (!spL.length) {
			const leagues = await spsg.getLeagues().catch(e => { setError('Failed to load leagues: ' + (e?.message||'unknown error')); return null; });
			if (!leagues) return;
			setSpL(leagues);
			return; // user picks from dropdown next click
		}
		const lg = spL.find(l => String(l.id)===importLg);
		if (!lg?.teams) return;
		// Fix #10: skip if division with same name already exists
		if (cfg.divisions.some(d => d.name === lg.name)) {
			setError(`Division "${lg.name}" already imported`);
			return;
		}
		up({divisions:[...cfg.divisions,{id:mkId('div'),name:lg.name,teams:lg.teams.map(t=>({id:mkId('team'),name:t.name,is_tbd:false}))}]});
		setImportLg('');
	};

	const addDiv = () => up(prev => ({divisions:[...prev.divisions,{id:mkId('div'),name:`Division ${prev.divisions.length+1}`,teams:[]}]}));
	const rmDiv = id => up(prev => ({divisions:prev.divisions.filter(d=>d.id!==id)}));
	const upDiv = (id,p) => up(prev => ({divisions:prev.divisions.map(d=>d.id===id?{...d,...p}:d)}));
	const addTeam = (did,tbd=false) => {
		const nm = tbd ? `TBD ${++tbdRef.current}` : 'New Team';
		upDiv(did,{teams:[...(cfg.divisions.find(d=>d.id===did)?.teams||[]),{id:mkId('team'),name:nm,is_tbd:tbd}]});
	};
	const rmTeam = (did,tid) => { const d=cfg.divisions.find(x=>x.id===did); if(d) upDiv(did,{teams:d.teams.filter(t=>t.id!==tid)}); };
	const upTeam = (did,tid,nm) => { const d=cfg.divisions.find(x=>x.id===did); if(d) upDiv(did,{teams:d.teams.map(t=>t.id===tid?{...t,name:nm}:t)}); };

	const doGen = async () => {
		setGenerating(true); setError('');
		try {
			const id = await save();
			// Gap #5/#6: auto-validate before generating
			const val = await spsg.validateConfig(id||configId);
			if (val.errors?.length) {
				setValidation(val);
				setGenerating(false);
				setError('Fix validation errors before generating.');
				return;
			}
			setValidation(val);
			const result = await spsg.generate(id||configId);
			const sched = { id: result.schedule_id, games: result.games||[], stats: computeStats(result.games), rich_stats: result.rich_stats||null };
			// Gap #3: persist to sessionStorage so user can return after navigating away
			try { sessionStorage.setItem(`spsg_sched_${id||configId}`, JSON.stringify(sched)); } catch {}
			setSchedule(sched);
			setGenerating(false); setStep(4);
		} catch(e) { setError(e?.message||'Failed to generate'); setGenerating(false); }
	};

	// Fix #7: track actual imported count
	const doPub = async () => {
		if (!schedule?.id||!pubSeason||!pubLeague) return;
		setPublishing(true); setPubProg({imported:0,total:schedule.games?.length||0}); let off=0, totalImported=0;
		try {
			let maxIter = 1000;
			while (true) {
				if (--maxIter <= 0) { setError('Publish loop exceeded maximum iterations'); break; }
				const r = await spsg.publish(schedule.id,pubSeason,pubLeague,off,50,pubOpts);
				totalImported += r.imported||0;
				off += 50;
				setPubProg({imported:totalImported,total:r.total||schedule.games?.length||0,dry_run:pubOpts.dry_run,skipped:r.skipped||0});
				if (r.remaining===0) break;
			}
			setPubProg(p=>({...p,done:true}));
			// Fix #2: load TBD placeholders after publish
			if (configId) {
				const ph = await spsg.getPlaceholders(configId).catch(()=>[]);
				if (ph.length) {
					setPlaceholders(ph);
					const teams = await spsg.getLeagues().then(ls=>ls.flatMap(l=>l.teams||[])).catch(()=>[]);
					setSpTeams(teams);
				}
			}
		} catch { setError('Publish failed'); }
		setPublishing(false);
	};

	const doReplace = async (placeholderId) => {
		const replacementId = replaceMap[placeholderId];
		if (!replacementId) return;
		await spsg.replacePlaceholder(placeholderId, replacementId, true).catch(()=>{});
		setPlaceholders(p=>p.filter(x=>x.id!==placeholderId));
	};

	// Fix #2: import config with preview
	const doImportConfig = async (e) => {
		const file = e.target.files?.[0]; if (!file) return;
		const text = await file.text();
		try {
			const data = JSON.parse(text);
			const cfg_data = data.configuration || data;
			// Show preview before importing
			setImportPreview({data: cfg_data, text});
		} catch { setError('Invalid config file'); }
		e.target.value = '';
	};

	const doImportConfirm = async () => {
		if (!importPreview) return;
		try {
			const cfg_data = importPreview.data;
			const r = await spsg.createConfig({...cfg_data, name: (cfg_data.name||'Imported')+' (Imported)'});
			const loaded = await spsg.getConfig(r.id);
			setCfg(loaded); setConfigId(r.id);
			loadConfigs(); setError(''); setImportPreview(null); setStep(1);
		} catch { setError('Import failed'); }
	};

	// Fix #11: format MySQL datetime to readable string
	const fmtDate = s => { if (!s) return ''; try { return new Date(s.replace(' ','T')).toLocaleDateString(); } catch { return s; } };
	// #9: format with time for history
	const fmtDateTime = s => { if (!s) return ''; try { return new Date(s.replace(' ','T')).toLocaleString(); } catch { return s; } };

	const divisionOptions = useMemo(() => [...new Set((schedule?.games||[]).map(x=>x.division).filter(Boolean))], [schedule?.games]);
	const teamOptions = useMemo(() => [...new Set((schedule?.games||[]).flatMap(x=>[x.home,x.away]).filter(Boolean))].sort(), [schedule?.games]);
	const venueOptions = useMemo(() => [...new Set((schedule?.games||[]).map(x=>x.venue).filter(Boolean))].sort(), [schedule?.games]);
	// UI-12: filter + sort the config list once per relevant input, not on every
	// render (search keystrokes, history toggles, etc.).
	const filteredConfigs = useMemo(() => {
		return [...configs.filter(c=>!cfgSearch||c.name.toLowerCase().includes(cfgSearch.toLowerCase()))].sort((a,b)=>{
			if(cfgSort==='name') return a.name.localeCompare(b.name);
			if(cfgSort==='teams') return (b.team_count||0)-(a.team_count||0);
			return 0; // default: server order (newest first)
		});
	}, [configs, cfgSearch, cfgSort]);
	const configsWithDrafts = useMemo(() => {
		const set = new Set();
		try {
			for (let i = 0; i < sessionStorage.length; i++) {
				const key = sessionStorage.key(i);
				if (key?.startsWith('spsg_sched_')) set.add(key.replace('spsg_sched_', ''));
			}
		} catch {}
		return set;
	}, [schedule]);

	const f = {display:'flex',gap:'0.5rem'};
	const g2 = {display:'grid',gridTemplateColumns:'1fr 1fr',gap:'0.75rem'};

	return (
		<>
		<div className="splm-wizard">
			<h2>Schedule Generator</h2>
			<Toast message={toast?.message} type={toast?.type} onDismiss={()=>setToast(null)}/>
			{error && <div className="splm-alert splm-alert--warning" role="alert">{error}</div>}
			{step>0&&step<4&&(
				<>
				<p className="screen-reader-text" aria-live="polite">Step {step} of {WIZARD_STEPS.length}: {WIZARD_STEPS[step-1]}</p>
				<ol className="splm-stepper">
					{WIZARD_STEPS.map((l,i)=>{
						const n=i+1;
						const reachable=canReach(n);
						return (
							<li key={i} className={`splm-stepper__item${step===n?' splm-stepper__item--current':''}`} aria-current={step===n?'step':undefined}>
								<button
									type="button"
									className="splm-stepper__btn"
									onClick={()=>go(n)}
									disabled={!reachable}
									aria-label={`Step ${n} of ${WIZARD_STEPS.length}: ${l}`}
								>
									<span className="splm-stepper__num" aria-hidden="true">{n}</span>
									<span>{l}</span>
								</button>
							</li>
						);
					})}
				</ol>
				</>
			)}

			{/* ── STEP 0: LAUNCHPAD ── */}
			{step===0&&(
				<div className="splm-wizard__step">
					<div className="splm-card">
						<h3>Saved Configurations</h3>
						{loading&&<div className="splm-loading">Loading…</div>}
						{!loading&&!configs.length&&<p className="splm-muted">No saved configs yet.</p>}
						{configs.length>0&&(
							<div className="splm-table-wrapper">
								{/* Gap #5: search filter + sort */}
								<div style={{display:'flex',gap:'0.5rem',marginBottom:'0.5rem'}}>
									<input className="splm-select" placeholder="Search…" value={cfgSearch} onChange={e=>setCfgSearch(e.target.value)} style={{flex:1}} aria-label="Search configurations"/>
									<select className="splm-select" style={{width:130}} value={cfgSort} onChange={e=>setCfgSort(e.target.value)} aria-label="Sort configurations">
										<option value="updated">Newest first</option>
										<option value="name">Name A–Z</option>
										<option value="teams">Most teams</option>
									</select>
								</div>
								<table className="splm-table">
									<thead><tr><th>Name</th><th>Updated</th><th>Divisions</th><th>Teams</th><th></th></tr></thead>
									<tbody>{filteredConfigs.map(c=>(
										<Fragment key={c.id}>
										<tr>
											{/* Gap #4: inline rename */}
											<td><input defaultValue={c.name} aria-label={`Rename configuration ${c.name}`} style={{border:'none',background:'transparent',width:'100%',padding:0}}
												onBlur={async e=>{ if(e.target.value!==c.name){await spsg.updateConfig(c.id,{name:e.target.value});loadConfigs();} }}
												onKeyDown={e=>{if(e.key==='Enter')e.target.blur();}}/></td>
											<td>{fmtDate(c.updated_at)}</td><td>{c.division_count}</td><td>{c.team_count}</td>
											<td style={f}>
												<button className="splm-btn" onClick={()=>{
													const saved = (() => { try { return JSON.parse(sessionStorage.getItem(`spsg_sched_${c.id}`)||'null'); } catch { return null; } })();
													spsg.getConfig(c.id).then(d=>{setCfg(d);setConfigId(c.id);
														// Gap #3: resume if saved schedule exists
														if (saved) { setSchedule(saved); setStep(4); } else { setStep(1); }
													});
												}}>{configsWithDrafts.has(c.id) ? 'Resume' : 'Load'}</button>
												{/* #11: clear draft */}
												{configsWithDrafts.has(c.id) && (
													<button className="splm-btn" title="Clear saved schedule draft" onClick={e=>{e.stopPropagation();try{sessionStorage.removeItem(`spsg_sched_${c.id}`);}catch{}loadConfigs();}}>✕ Draft</button>
												)}
												<button className="splm-btn splm-btn--danger" aria-label={`Delete ${c.name}`} onClick={()=>{ if(window.confirm(`Delete "${c.name}"?`)) spsg.deleteConfig(c.id).then(loadConfigs); }}>✕</button>
												<button className="splm-btn" title="Export JSON" aria-label={`Export ${c.name} as JSON`} onClick={async()=>{
													const data = await spsg.getConfig(c.id);
													const blob = new Blob([JSON.stringify({configuration:data},null,2)],{type:'application/json'});
													const a = document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=`${c.name||'config'}.json`; a.click(); URL.revokeObjectURL(a.href);
												}}>↓</button>
												{/* Gap #11: change history */}
												<button className="splm-btn" title="Change history" aria-label={`View change history for ${c.name}`} onClick={async()=>{
													if (historyId===c.id) { setHistoryId(null); return; }
													const h = await spsg.getHistory(c.id).catch(()=>[]);
													setHistoryData(h); setHistoryId(c.id);
												}}>⏱</button>
											</td>
										</tr>
										{/* Inline history panel */}
										{historyId===c.id&&(
											<tr>
												<td colSpan={5} style={{background:'var(--splm-surface-alt)',padding:'0.75rem'}}>
													{!historyData.length
														? <p className="splm-muted">No change history recorded.</p>
														: <><table className="splm-table" style={{fontSize:'0.85em'}}>
															<thead><tr><th>When</th><th>Field</th><th>From</th><th>To</th></tr></thead>
															<tbody>{historyData.map((e,i)=>(
																<tr key={i}>
																	<td>{fmtDateTime(e.timestamp)}</td>
																	<td>{e.field_label||e.field}</td>
																	<td style={{color:'var(--splm-danger)',maxWidth:200,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{e.old_value}</td>
																	<td style={{color:'var(--splm-success)',maxWidth:200,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}>{e.new_value}</td>
																</tr>
															))}</tbody>
														</table>
														{/* #3: clear history */}
														<button className="splm-btn splm-btn--danger" style={{marginTop:'0.5rem',fontSize:'0.8em'}} onClick={async()=>{
															if(window.confirm('Clear all change history for this config?')){
																await spsg.clearHistory(c.id).catch(()=>{});
																setHistoryData([]); setHistoryId(null);
															}
														}}>Clear History</button></>
													}
												</td>
											</tr>
										)}
										</Fragment>
									))}</tbody>
								</table>
							</div>
						)}
					</div>
					<div className="splm-wizard__actions">
						{configs.length>0&&(
							<button className="splm-btn splm-btn--primary" onClick={async()=>{
								const c=configs[0];
								try {
									// Fix #1/#9: clone returns {id}, must load full config separately
									const cloned = await spsg.cloneConfig(c.id,`${c.name} (copy)`);
									const full = await spsg.getConfig(cloned.id);
									setCfg(full); setConfigId(cloned.id); setStep(1);
								} catch { setError('Clone failed'); }
							}}>Start from {configs[0]?.name}</button>
						)}
						<button className="splm-btn" onClick={()=>{setCfg(blank());setConfigId(null);setStep(1);}}>Start Fresh</button>
						{/* #1: Preset quick-start. UX-6: real button menu, keyboard
						    activatable, dismiss on outside-click / Escape. */}
						{presets&&Object.keys(presets).length>0&&(
							<div style={{display:'inline-block',position:'relative'}} ref={presetRef}>
								<button type="button" className="splm-btn" aria-haspopup="menu" aria-expanded={presetOpen} onClick={()=>setPresetOpen(o=>!o)}>Use Preset ▾</button>
								{presetOpen&&(
									<div role="menu" style={{position:'absolute',background:'var(--splm-surface)',border:'1px solid var(--splm-border)',borderRadius:4,padding:'0.5rem',zIndex:10,minWidth:220,boxShadow:'0 2px 8px rgba(0,0,0,0.1)'}}>
										{Object.entries(presets).map(([key,p])=>(
											<button key={key} type="button" role="menuitem" className="splm-more-menu__item" style={{flexDirection:'column',alignItems:'flex-start'}} onClick={async()=>{
												setPresetOpen(false);
												const preset = await spsg.getPreset(key).catch(()=>null);
												if (preset) { setCfg({...blank(),...preset,name:p.name}); setConfigId(null); setStep(1); }
											}}>
												<strong style={{display:'block'}}>{p.name}</strong>
												<span style={{fontSize:'0.8em',color:'var(--splm-muted)'}}>{p.description}</span>
											</button>
										))}
									</div>
								)}
							</div>
						)}
						{/* Fix #7: import config */}
						<button className="splm-btn" onClick={()=>importRef.current?.click()}>Import JSON</button>
						<input ref={importRef} type="file" accept=".json" style={{display:'none'}} onChange={doImportConfig}/>
					</div>
					{/* #2: Import preview modal */}
					{importPreview&&(
						<div className="splm-card" style={{marginTop:'0.75rem',borderLeft:'3px solid var(--splm-primary)'}}>
							<h4>Import Preview</h4>
							<table className="splm-table" style={{fontSize:'0.85em',marginBottom:'0.75rem'}}>
								<tbody>
									<tr><td><strong>Name</strong></td><td>{importPreview.data.name||'(unnamed)'}</td></tr>
									<tr><td><strong>Season</strong></td><td>{importPreview.data.season_start||importPreview.data.start_date||'?'} → {importPreview.data.season_end||importPreview.data.end_date||'?'}</td></tr>
									<tr><td><strong>Games/team</strong></td><td>{importPreview.data.games_per_team||'?'}</td></tr>
									<tr><td><strong>Divisions</strong></td><td>{(importPreview.data.divisions||[]).length} ({(importPreview.data.divisions||[]).reduce((s,d)=>s+(d.teams?.length||0),0)} teams)</td></tr>
									<tr><td><strong>Venues</strong></td><td>{(importPreview.data.venues||[]).length}</td></tr>
								</tbody>
							</table>
							<div style={{display:'flex',gap:'0.5rem'}}>
								<button className="splm-btn splm-btn--primary" onClick={doImportConfirm}>Import & Edit</button>
								<button className="splm-btn" onClick={()=>setImportPreview(null)}>Cancel</button>
							</div>
						</div>
					)}
				</div>
			)}

			{/* ── STEP 1: TEAMS & SEASON ── */}
			{step===1&&(
				<div className="splm-wizard__step">
					<div className="splm-card">
						<h3>Who &amp; When</h3>
						<div style={g2}>
							<div style={{gridColumn:'1/-1'}}><label htmlFor="spsg-name">Config Name</label><input id="spsg-name" className="splm-select" value={cfg.name} onChange={e=>up({name:e.target.value})}/></div>
							<div><label htmlFor="spsg-start">Start Date</label><input id="spsg-start" type="date" className="splm-select" value={cfg.start_date} onChange={e=>up({start_date:e.target.value})}/></div>
							<div><label htmlFor="spsg-end">End Date</label><input id="spsg-end" type="date" className="splm-select" value={cfg.end_date} onChange={e=>up({end_date:e.target.value})}/></div>
							<div><label htmlFor="spsg-gpt">Games per Team</label><input id="spsg-gpt" type="number" className="splm-select" min="1" value={cfg.games_per_team} onChange={e=>up({games_per_team:parseInt(e.target.value,10)||0})}/>
							{/* #1: matchup feasibility hint */}
							{(()=>{
								const tt = cfg.divisions.reduce((s,d)=>s+d.teams.length,0);
								if (tt < 2) return null;
								const minGames = cfg.matchup_style==='single_round_robin' ? tt-1 : (tt-1)*2;
								if (cfg.games_per_team > 0 && cfg.games_per_team !== minGames) {
									return <p style={{fontSize:'0.8em',color:'var(--splm-muted)',marginTop:'0.2rem'}}>
										{tt} teams × {cfg.matchup_style==='double_round_robin'?'double':'single'} RR = {minGames} games/team
									</p>;
								}
								return null;
							})()}
						</div>
							<div><label htmlFor="spsg-mlen">Match Length (min)</label><input id="spsg-mlen" type="number" className="splm-select" min="1" value={cfg.match_length} onChange={e=>up({match_length:parseInt(e.target.value,10)||60})}/></div>
							<div><label htmlFor="spsg-style">Matchup Style</label>
								<select id="spsg-style" className="splm-select" value={cfg.matchup_style} onChange={e=>up({matchup_style:e.target.value})}>
									<option value="single_round_robin">Single Round Robin</option>
									<option value="double_round_robin">Double Round Robin</option>
								</select>
							</div>
						</div>
						<div style={{marginTop:'0.75rem'}}>
							<label>Playing Days</label>
							<div style={{...f,flexWrap:'wrap',gap:'0.75rem'}}>
								{DAYS.map(d=><label key={d} className="splm-checkbox"><input type="checkbox" checked={cfg.playing_days.includes(d)} onChange={()=>togDay(d)}/> {DL[d]}</label>)}
							</div>
						</div>
					</div>

					<div className="splm-card">
						<h3>Divisions</h3>
						<div style={{...f,marginBottom:'0.75rem',flexWrap:'wrap'}}>
							<button className="splm-btn" onClick={addDiv}>Add Division</button>
							<button className="splm-btn" onClick={doImport}>{spL.length?'Import Selected':'Import from SportsPress'}</button>
							{spL.length>0&&<>
								<select className="splm-select" aria-label="Division to import" value={importLg} onChange={e=>setImportLg(e.target.value)}><option value="">Pick a division…</option>{spL.filter(l=>l.name.toUpperCase()!=='ALL').map(l=><option key={l.id} value={l.id}>{l.name} ({l.teams?.length||0})</option>)}</select>
								{/* #2: Import All always visible once leagues loaded */}
								<button className="splm-btn" title="Import all divisions" onClick={()=>{
									const newDivs = spL.filter(l => l.teams?.length && l.name.toUpperCase()!=='ALL' && !cfg.divisions.some(d=>d.name===l.name))
										.map(l => ({id:mkId('div'),name:l.name,teams:l.teams.map(t=>({id:mkId('team'),name:t.name,is_tbd:false}))}));
									if (newDivs.length) up({divisions:[...cfg.divisions,...newDivs]});
									else setError('All leagues already imported');
								}}>Import All Divisions</button>
							</>}
						</div>
						{/* Fix #12: generic_teams auto-fill */}
						<details style={{marginBottom:'0.75rem'}}>
							<summary style={{cursor:'pointer',color:'var(--splm-muted)'}}>TBD Team Auto-fill</summary>
							<div style={{...f,alignItems:'center',marginTop:'0.5rem',flexWrap:'wrap',gap:'0.5rem'}}>
								<label className="splm-checkbox"><input type="checkbox" checked={cfg.generic_teams.enabled} onChange={e=>up({generic_teams:{...cfg.generic_teams,enabled:e.target.checked}})}/> Enable</label>
								<label>Teams per division: <input type="number" className="splm-select" min="0" style={{width:70}} value={cfg.generic_teams.per_division} onChange={e=>up({generic_teams:{...cfg.generic_teams,per_division:parseInt(e.target.value,10)||0}})}/></label>
								<label>Prefix: <input className="splm-select" style={{width:100}} value={cfg.generic_teams.prefix} onChange={e=>up({generic_teams:{...cfg.generic_teams,prefix:e.target.value}})}/></label>
							</div>
						</details>
						{cfg.divisions.map(div=>(
							<div key={div.id} className="splm-card" style={{marginBottom:'0.75rem'}}>
								<div style={{...f,alignItems:'center',marginBottom:'0.5rem'}}>
									<input className="splm-select" value={div.name} onChange={e=>upDiv(div.id,{name:e.target.value})} style={{flex:1}} aria-label={`Division name (${div.name})`}/>
									{/* #6: duplicate division name warning */}
									{cfg.divisions.filter(d=>d.id!==div.id&&d.name===div.name).length>0&&<span style={{color:'var(--splm-danger)',fontSize:'0.8em',marginLeft:'0.25rem'}}>⚠️ Duplicate</span>}
									{/* UX-20: aria-label + confirm before deleting a populated division. */}
									<button className="splm-btn splm-btn--danger" aria-label={`Remove division ${div.name}`} onClick={()=>{ if(!div.teams.length||window.confirm(`Remove division "${div.name}" and its ${div.teams.length} team(s)?`)) rmDiv(div.id); }}>✕</button>
								</div>
								{div.teams.map(t=>(
									<div key={t.id} style={{...f,alignItems:'center',marginBottom:'0.25rem',paddingLeft:'1rem'}}>
										{t.is_tbd&&<span className="splm-badge splm-badge--warning">TBD</span>}
										<input className="splm-select" value={t.name} onChange={e=>upTeam(div.id,t.id,e.target.value)} style={{flex:1}} aria-label={`Team name (${t.name})`}/>
										{/* Gap #6: move team to another division */}
										{cfg.divisions.length>1&&(
											<select className="splm-select" style={{width:90}} value="" aria-label={`Move ${t.name} to another division`} onChange={e=>{
												if(!e.target.value) return;
												const targetDivId = e.target.value;
												up(prev => {
													const srcDiv = prev.divisions.find(d=>d.id===div.id);
													const tgtDiv = prev.divisions.find(d=>d.id===targetDivId);
													if (!srcDiv || !tgtDiv) return prev;
													return {
														...prev,
														divisions: prev.divisions.map(d => {
															if (d.id === div.id) return {...d, teams: d.teams.filter(tm=>tm.id!==t.id)};
															if (d.id === targetDivId) return {...d, teams: [...d.teams, t]};
															return d;
														})
													};
												});
												e.target.value='';
											}}>
												<option value="">Move…</option>
												{cfg.divisions.filter(d=>d.id!==div.id).map(d=><option key={d.id} value={d.id}>{d.name}</option>)}
											</select>
										)}
										<button className="splm-btn splm-btn--danger" aria-label={`Remove ${t.name}`} onClick={()=>rmTeam(div.id,t.id)} style={{padding:'0.2rem 0.5rem'}}>✕</button>
									</div>
								))}
								<div style={{...f,marginTop:'0.5rem',paddingLeft:'1rem'}}>
									<button className="splm-btn" onClick={()=>addTeam(div.id)}>Add Team</button>
									<button className="splm-btn" onClick={()=>addTeam(div.id,true)}>Add TBD</button>
									{/* #4: per-division SP team loading */}
									{spL.length>0&&(
										<select className="splm-select" style={{width:140}} value="" aria-label="Load teams from SportsPress" onChange={async e=>{
											if(!e.target.value) return;
											const teams = await spsg.getLeagueTeams(e.target.value).catch(()=>[]);
											const existing = new Set(div.teams.map(t=>t.name));
											const newTeams = teams.filter(t=>!existing.has(t.name)).map(t=>({id:mkId('team'),name:t.name,is_tbd:false}));
											if(newTeams.length) upDiv(div.id,{teams:[...div.teams,...newTeams]});
											e.target.value='';
										}}>
											<option value="">Load from SP…</option>
											{spL.filter(l=>l.name.toUpperCase()!=='ALL').map(l=><option key={l.id} value={l.id}>{l.name}</option>)}
										</select>
									)}
								</div>
							</div>
						))}
					</div>
					<div className="splm-wizard__actions">
						{/* Fix #19: use go(0) so changes are saved before going back */}
						<button className="splm-btn" onClick={()=>go(0)}>Back</button>
						<button className="splm-btn splm-btn--primary" onClick={()=>go(2)}>Next: Rinks &amp; Times →</button>
					</div>
				</div>
			)}

			{/* ── STEP 2: RINKS & TIMES ── */}
			{step===2&&(
				<div className="splm-wizard__step">
					<div className="splm-card">
						<h3>Where</h3>
						<VenueSel venues={spV} selected={cfg.venues} onLoad={()=>spsg.getVenues().then(setSpV)} onChange={v=>up({venues:v})}/>
					</div>
					<div className="splm-card">
						<h3>Time Slots per Playing Day</h3>
						{/* Gap #4: pre-fill defaults */}
						<button className="splm-btn" style={{marginBottom:'0.5rem'}} onClick={()=>{
							const hasExisting = Object.values(cfg.time_slots).some(s=>s.length>0);
							// #8: confirm before overwriting existing slots
							if (hasExisting && !window.confirm('Replace existing time slots with defaults?')) return;
							const sl = {};
							cfg.playing_days.forEach(d => {
								sl[d] = d==='saturday'||d==='sunday' ? ['14:00','15:00','16:00'] : ['19:00','20:00','21:00'];
							});
							up({time_slots:sl});
						}}>Use Defaults</button>
						{cfg.playing_days.map(day=>(
							<div key={day} style={{marginBottom:'0.75rem'}}>
								<strong>{DL[day]||day}</strong>
								{/* Gap #3: wrap time slots */}
								<div style={{display:'flex',flexWrap:'wrap',gap:'0.25rem',marginTop:'0.25rem'}}>
								{(cfg.time_slots[day]||[]).map((t,i)=>(
									<span key={i} style={{display:'inline-flex',gap:'0.25rem',alignItems:'center'}}>
										<input type="time" className="splm-select" value={t} onChange={e=>{const sl={...cfg.time_slots};sl[day]=[...(sl[day]||[])];sl[day][i]=e.target.value;up({time_slots:sl});}}/>
										<button className="splm-btn splm-btn--danger" style={{padding:'0.1rem 0.4rem'}} onClick={()=>{const sl={...cfg.time_slots};sl[day]=sl[day].filter((_,j)=>j!==i);up({time_slots:sl});}}>✕</button>
									</span>
								))}
								<button className="splm-btn" onClick={()=>{const sl={...cfg.time_slots};sl[day]=[...(sl[day]||[]),'19:00'];up({time_slots:sl});}}>+ Time</button>
								</div>
							</div>
						))}
						{!cfg.playing_days.length&&<p className="splm-muted">Select playing days in Step 1 first.</p>}
					</div>
					<div className="splm-card">
						<h3>Blackout Dates</h3>
						<textarea className="splm-textarea" rows="3" placeholder="One date per line (YYYY-MM-DD)" value={cfg.blackout_dates} onChange={e=>up({blackout_dates:e.target.value})}/>
					</div>
					{/* Fix #4: per-venue blackout dates */}
					{cfg.venues.length>0&&(
						<details className="splm-card" style={{marginTop:'0.75rem'}}>
							<summary style={{cursor:'pointer',fontWeight:600}}>Per-Venue Blackout Dates</summary>
							<div style={{marginTop:'0.75rem'}}>
								{spV.filter(v=>cfg.venues.includes(v.id)).map(v=>(
									<div key={v.id} style={{marginBottom:'0.75rem'}}>
										<label style={{fontWeight:600}}>{v.name}</label>
										<textarea className="splm-textarea" rows="2" placeholder="One date per line (YYYY-MM-DD)"
											value={(cfg.venue_blackout_dates[v.id]||[]).join('\n')}
											onChange={e=>up({venue_blackout_dates:{...cfg.venue_blackout_dates,[v.id]:e.target.value.split('\n').map(s=>s.trim()).filter(Boolean)}})}/>
									</div>
								))}
							</div>
						</details>
					)}
					{/* Gap #1: per-venue time slot overrides */}
					{cfg.venues.length>0&&cfg.playing_days.length>0&&(
						<details className="splm-card" style={{marginTop:'0.75rem'}}>
							<summary style={{cursor:'pointer',fontWeight:600}}>Per-Venue Time Slot Overrides</summary>
							<p className="splm-muted" style={{marginTop:'0.5rem',marginBottom:'0.75rem'}}>Override global time slots for specific venues. Leave empty to use global slots.</p>
							{spV.filter(v=>cfg.venues.includes(v.id)).map(v=>(
								<div key={v.id} style={{marginBottom:'0.75rem'}}>
									<label style={{fontWeight:600}}>{v.name}</label>
									{cfg.playing_days.map(day=>(
										<div key={day} style={{marginLeft:'1rem',marginTop:'0.25rem'}}>
											<strong style={{fontSize:'0.85em'}}>{DL[day]||day}</strong>
											<div style={{display:'flex',flexWrap:'wrap',gap:'0.25rem',marginTop:'0.15rem'}}>
												{((cfg.venue_timeslots[v.id]||{})[day]||[]).map((t,i)=>(
													<span key={i} style={{display:'inline-flex',gap:'0.25rem',alignItems:'center'}}>
														<input type="time" className="splm-select" value={t} onChange={e=>{
															const vt={...cfg.venue_timeslots};
															vt[v.id]={...(vt[v.id]||{}),[day]:[...((vt[v.id]||{})[day]||[])]};
															vt[v.id][day][i]=e.target.value;
															up({venue_timeslots:vt});
														}}/>
														<button className="splm-btn splm-btn--danger" style={{padding:'0.1rem 0.4rem'}} onClick={()=>{
															const vt={...cfg.venue_timeslots};
															vt[v.id]={...(vt[v.id]||{}),[day]:((vt[v.id]||{})[day]||[]).filter((_,j)=>j!==i)};
															up({venue_timeslots:vt});
														}}>✕</button>
													</span>
												))}
												<button className="splm-btn" onClick={()=>{
													const vt={...cfg.venue_timeslots};
													vt[v.id]={...(vt[v.id]||{}),[day]:[...((vt[v.id]||{})[day]||[]),'19:00']};
													up({venue_timeslots:vt});
												}}>+ Time</button>
											</div>
										</div>
									))}
								</div>
							))}
						</details>
					)}
					<Cap cfg={cfg}/>
					{/* Distribution settings from admin */}
					{distSettings&&(Object.values(distSettings.day_weights||{}).some(w=>w>0))&&(
						<div className="splm-card" style={{marginTop:'0.75rem',borderLeft:'3px solid var(--splm-primary)'}}>
							<p style={{margin:0,fontSize:'0.85em'}}>
								<strong>Day weights from admin settings:</strong>{' '}
								{Object.entries(distSettings.day_weights).filter(([,w])=>w>0).map(([d,w])=>`${d.charAt(0).toUpperCase()+d.slice(1,3)}: ${w}`).join(', ')}
								{' '}— will be applied during generation.
							</p>
						</div>
					)}
					{/* Venue CSV import */}
					<details className="splm-card" style={{marginTop:'0.75rem'}}>
						<summary style={{cursor:'pointer',fontWeight:600}}>Import Venue Schedule from CSV</summary>
						<div style={{marginTop:'0.75rem'}}>
							<p className="splm-muted" style={{marginBottom:'0.5rem'}}>CSV format: <code>Week Start Date, Venue Name, Time Slots</code> (e.g. <code>2026-09-01, Appleby 1, 18:00-23:00</code>)</p>
							<div style={{display:'flex',gap:'0.5rem',alignItems:'center',flexWrap:'wrap'}}>
								<input ref={csvRef} type="file" accept=".csv" style={{display:'none'}} onChange={async e=>{
									const file = e.target.files?.[0]; if (!file) return;
									const fd = new FormData(); fd.append('csv', file);
									try {
										const r = await spsg.parseVenueCsv(fd);
										setCsvParsed(r);
										// Pre-fill mapping from suggestions
										const m = {};
										(r.suggestions||[]).forEach(s=>{ if(s.match_id) m[s.csv_venue]=s.match_id; });
										setCsvMapping(m);
									} catch(e) { setError(e?.message||'CSV parse failed'); }
									e.target.value='';
								}}/>
								<button className="splm-btn" onClick={()=>csvRef.current?.click()}>Choose CSV File</button>
								{csvParsed&&<span className="splm-muted">{csvParsed.row_count} rows parsed, {csvParsed.csv_venues?.length} venues</span>}
							</div>
							{csvParsed&&(
								<div style={{marginTop:'0.75rem'}}>
									<h5>Map CSV Venues to SportsPress Venues</h5>
									{(csvParsed.csv_venues||[]).map(v=>(
										<div key={v} style={{display:'flex',gap:'0.5rem',alignItems:'center',marginBottom:'0.25rem'}}>
											<span style={{minWidth:150}}>{v}</span>
											<select className="splm-select" aria-label={`Map ${v} to SportsPress venue`} value={csvMapping[v]||''} onChange={e=>setCsvMapping(m=>({...m,[v]:e.target.value}))}>
												<option value="">Skip</option>
												{(csvParsed.sp_venues||[]).map(sv=><option key={sv.id} value={sv.id}>{sv.name}</option>)}
												<option value="__new__">Create new venue</option>
											</select>
										</div>
									))}
									<button className="splm-btn splm-btn--primary" style={{marginTop:'0.5rem'}} onClick={async()=>{
										if (!configId) { setError('Save config first (click Next then Back)'); return; }
										// UX-7: confirm this config-mutating action; in-app toast on success.
										if (!window.confirm('Apply the imported venue schedule to this configuration? This overwrites matching venue time slots.')) return;
										const mapping = {};
										Object.entries(csvMapping).forEach(([csv,id])=>{ if(id&&id!=='__new__') mapping[csv]=parseInt(id,10); });
										try {
											const r = await spsg.applyVenueCsv(csvParsed.schedules, mapping, configId);
											setCsvParsed(null); setCsvMapping({});
											setError('');
											setToast({message:`Applied venue schedule for ${r.applied} venue(s).`,type:'success'});
										} catch(e) { setError(e?.message||'Apply failed'); }
									}}>Apply to Config</button>
								</div>
							)}
						</div>
					</details>
					<div className="splm-wizard__actions">
						<button className="splm-btn" onClick={()=>go(1)}>← Back</button>
						<button className="splm-btn splm-btn--primary" onClick={()=>go(3)}>Next: Review →</button>
					</div>
				</div>
			)}

			{/* ── STEP 3: REVIEW & GENERATE ── */}
			{step===3&&(
				<div className="splm-wizard__step">
					<div style={{display:'grid',gridTemplateColumns:'1fr 1fr 1fr',gap:'0.75rem'}}>
						<div className="splm-card"><h4>Teams</h4><p>{cfg.divisions.reduce((s,d)=>s+d.teams.length,0)} teams in {cfg.divisions.length} divisions</p></div>
						<div className="splm-card"><h4>Season</h4><p>{cfg.start_date} → {cfg.end_date}<br/>{cfg.games_per_team} games/team</p></div>
						<div className="splm-card"><h4>Venues</h4><p>{cfg.venues.length} venues, {Object.values(cfg.time_slots).reduce((s,a)=>s+a.length,0)} slots</p></div>
					</div>
					<div className="splm-card" style={{marginTop:'0.75rem'}}>
						<button className="splm-btn" onClick={async()=>{
							try { const id=await save(); setValidation(await spsg.validateConfig(id||configId)); }
							catch { setValidation({errors:['Validation failed']}); }
						}}>Validate Config</button>
						{validation&&<div style={{marginTop:'0.5rem'}}>
							{(validation.errors||[]).map((e,i)=><div key={i} className="splm-alert splm-alert--error" style={{marginBottom:'0.25rem'}}>❌ {e}</div>)}
							{(validation.warnings||[]).map((w,i)=><div key={i} className="splm-alert splm-alert--warning" style={{marginBottom:'0.25rem'}}>⚠️ {w}</div>)}
							{!validation.errors?.length&&!validation.warnings?.length&&<p style={{color:'var(--splm-success)'}}>✅ Config looks good!</p>}
						</div>}
					</div>
					<details className="splm-card" style={{marginTop:'0.75rem'}}>
						<summary style={{cursor:'pointer',fontWeight:600}}>Advanced Options</summary>
						<div style={{marginTop:'0.75rem'}}>
							<label>Back-to-back avoidance pairs (comma-separated per line)</label>
							<textarea className="splm-textarea" rows="2"
								value={(cfg.advanced.b2b_pairs||[]).map(p=>p.join(',')).join('\n')}
								onChange={e=>up({advanced:{...cfg.advanced,b2b_pairs:e.target.value.split('\n').filter(Boolean).map(l=>l.split(',').map(s=>s.trim()))}})}/>
							{/* Fix #20: overlap avoidance */}
							<label style={{marginTop:'0.5rem',display:'block'}}>Overlap avoidance pairs (comma-separated per line, optional buffer minutes after colon)</label>
							<textarea className="splm-textarea" rows="2" placeholder="e.g. Team A,Team B:30"
								value={(cfg.advanced.overlap_pairs||[]).map(p=>p.teams.join(',')+(p.buffer_minutes?':'+p.buffer_minutes:'')).join('\n')}
								onChange={e=>up({advanced:{...cfg.advanced,overlap_pairs:e.target.value.split('\n').filter(Boolean).map(l=>{const[teams,buf]=l.split(':');return{teams:teams.split(',').map(s=>s.trim()),buffer_minutes:buf?parseInt(buf,10):0};})}})}/>
							{/* Gap #10: warn on unknown team names */}
							{(()=>{const names=new Set(cfg.divisions.flatMap(d=>d.teams.map(t=>t.name)));const unk=[...(cfg.advanced.b2b_pairs||[]),...(cfg.advanced.overlap_pairs||[]).map(p=>p.teams||[])].flat().filter(n=>n&&!names.has(n));return unk.length?<p style={{color:'var(--splm-danger)',fontSize:'0.85em',marginTop:'0.25rem'}}>⚠️ Unknown teams: {[...new Set(unk)].join(', ')}</p>:null;})()}
							<label style={{marginTop:'0.5rem',display:'block'}}>Inter-division games</label>
							{cfg.divisions.length>1&&cfg.divisions.map((d1,i)=>cfg.divisions.slice(i+1).map(d2=>(
								<div key={`${d1.id}-${d2.id}`} style={{display:'flex',gap:'0.5rem',alignItems:'center',marginBottom:'0.25rem'}}>
									<span>{d1.name} ↔ {d2.name}</span>
									<input type="number" className="splm-select" min="0" style={{width:80}}
										value={cfg.advanced.inter_division[`${d1.id}:${d2.id}`]||0}
										onChange={e=>up({advanced:{...cfg.advanced,inter_division:{...cfg.advanced.inter_division,[`${d1.id}:${d2.id}`]:parseInt(e.target.value,10)||0}}})}/>
								</div>
							)))}
							<label style={{marginTop:'0.5rem',display:'block'}}>Home venue preferences</label>
							{cfg.divisions.flatMap(d=>d.teams).map(t=>(
								<div key={t.id} style={{display:'flex',gap:'0.5rem',alignItems:'center',marginBottom:'0.25rem'}}>
									<span style={{minWidth:120}}>{t.name}</span>
									<select className="splm-select" aria-label={`Home venue preference for ${t.name}`} value={cfg.advanced.venue_prefs[t.id]||''}
										onChange={e=>up({advanced:{...cfg.advanced,venue_prefs:{...cfg.advanced.venue_prefs,[t.id]:e.target.value}}})}>
										<option value="">Any</option>
										{spV.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}
									</select>
								</div>
							))}
						</div>
					</details>
					{/* Fix #11: no cancel button, just spinner. UX-18: announce status. */}
					{generating&&(
						<div className="splm-card" style={{marginTop:'0.75rem'}} role="status" aria-live="polite" aria-busy="true">
							<strong>Generating schedule…</strong>
							<div style={{height:8,background:'var(--splm-border)',borderRadius:4,marginTop:6}}>
								<div style={{width:'100%',height:'100%',background:'var(--splm-primary)',borderRadius:4,animation:'splm-pulse 1.5s ease-in-out infinite'}}/>
							</div>
						</div>
					)}
					<div className="splm-wizard__actions">
						<button className="splm-btn" onClick={()=>go(2)}>← Back</button>
						<button className="splm-btn splm-btn--primary" onClick={doGen} disabled={generating||(validation?.errors?.length>0)}>
							{generating?'Generating…':'Generate Schedule'}
						</button>
					</div>
				</div>
			)}

			{/* ── STEP 4: PREVIEW & PUBLISH ── */}
			{step===4&&schedule&&(
				<div className="splm-wizard__step">
					<div className="splm-card">
						<h3>Schedule Preview ({schedule.games?.length||0} games)</h3>
						{/* #8: Preview filters — division, team, venue, date range */}
						<div style={{display:'flex',gap:'0.5rem',flexWrap:'wrap',marginBottom:'0.5rem'}}>
							<select className="splm-select" aria-label="Filter by division" value={divF} onChange={e=>setDivF(e.target.value)}>
								<option value="">All Divisions</option>
								{divisionOptions.map(d=><option key={d} value={d}>{d}</option>)}
							</select>
							<select className="splm-select" aria-label="Filter by team" value={previewFilters?.team||''} onChange={e=>setPreviewFilters(f=>({...f,team:e.target.value}))}>
								<option value="">All Teams</option>
								{teamOptions.map(t=><option key={t} value={t}>{t}</option>)}
							</select>
							<select className="splm-select" aria-label="Filter by venue" value={previewFilters?.venue||''} onChange={e=>setPreviewFilters(f=>({...f,venue:e.target.value}))}>
								<option value="">All Venues</option>
								{venueOptions.map(v=><option key={v} value={v}>{v}</option>)}
							</select>
							<input type="date" className="splm-select" style={{width:140}} value={previewFilters?.from||''} onChange={e=>setPreviewFilters(f=>({...f,from:e.target.value}))} title="From date"/>
							<input type="date" className="splm-select" style={{width:140}} value={previewFilters?.to||''} onChange={e=>setPreviewFilters(f=>({...f,to:e.target.value}))} title="To date"/>
							{(divF||previewFilters?.team||previewFilters?.venue||previewFilters?.from||previewFilters?.to)&&<button className="splm-btn" onClick={()=>{setDivF('');setPreviewFilters({});}}>Clear</button>}
						</div>
						<div className="splm-table-wrapper">
							<table className="splm-table">
								<thead><tr><th>Date</th><th>Time</th><th>Home</th><th>Away</th><th>Venue</th><th>Division</th></tr></thead>
								<tbody>
									{(schedule.games||[]).filter(x=>{
										if (divF && x.division!==divF) return false;
										if (previewFilters?.team && x.home!==previewFilters.team && x.away!==previewFilters.team) return false;
										if (previewFilters?.venue && x.venue!==previewFilters.venue) return false;
										if (previewFilters?.from && x.date < previewFilters.from) return false;
										if (previewFilters?.to && x.date > previewFilters.to) return false;
										return true;
									}).map((x,i)=>(
										<tr key={i}>
											<td>{x.date}</td><td>{x.time}</td>
											<td><Tbd name={x.home}/></td><td><Tbd name={x.away}/></td>
											<td>{x.venue}</td><td>{x.division}</td>
										</tr>
									))}
								</tbody>
							</table>
						</div>
						{/* Export buttons */}
						<div style={{display:'flex',gap:'0.5rem',marginTop:'0.5rem',flexWrap:'wrap',alignItems:'center'}}>
							<button className="splm-btn" onClick={()=>{
								const rows=[['Date','Time','Home','Away','Venue','Division']];
								(schedule.games||[]).forEach(g=>rows.push([g.date,g.time,g.home,g.away,g.venue,g.division]));
								const csv=rows.map(r=>r.map(c=>`"${(c||'').replace(/"/g,'""')}"`).join(',')).join('\n');
								const fname=`${(cfg.name||'schedule').replace(/[^a-z0-9]/gi,'_')}_${new Date().toISOString().split('T')[0]}.csv`;
								const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download=fname;a.click();URL.revokeObjectURL(a.href);
							}}>Export CSV</button>
							{/* #9: XLSX style selector */}
							<select className="splm-select" style={{width:120}} aria-label="XLSX export style" value={xlsxStyle} onChange={e=>setXlsxStyle(e.target.value)}>
								<option value="detailed">XLSX Detailed</option>
								<option value="compact">XLSX Compact</option>
							</select>
							<button className="splm-btn" onClick={async()=>{
								try { const r=await spsg.exportXlsx(schedule.id,configId,xlsxStyle); window.open(r.url,'_blank'); }
								catch(e) { setError(e?.message||'XLSX export failed'); }
							}}>Export XLSX</button>
						</div>
					</div>

					{/* #7: Detailed stats — team stats + venue utilization + home/away balance + imbalances */}
					{schedule.rich_stats&&(
						<details className="splm-card" style={{marginTop:'0.75rem'}}>
							<summary style={{cursor:'pointer',fontWeight:600}}>Detailed Statistics</summary>
							<div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:'0.75rem',marginTop:'0.75rem'}}>
								{/* Home/Away Balance */}
								{schedule.rich_stats.home_away_balance&&Object.keys(schedule.rich_stats.home_away_balance).length>0&&(
									<div>
										<h5 style={{marginBottom:'0.5rem'}}>Home/Away Balance</h5>
										<table className="splm-table" style={{fontSize:'0.85em'}}>
											<thead><tr><th>Team</th><th>Home</th><th>Away</th><th>Balance</th></tr></thead>
											<tbody>{Object.values(schedule.rich_stats.home_away_balance).map((t,i)=>{
												const diff = t.home - t.away;
												const bal = diff===0 ? <span style={{color:'var(--splm-success)'}}>✓</span> : <span style={{color:Math.abs(diff)>2?'var(--splm-danger)':'var(--splm-warn-amber)'}}>{diff>0?'+':''}{diff}</span>;
												return <tr key={i}><td>{t.team_name}</td><td>{t.home}</td><td>{t.away}</td><td>{bal}</td></tr>;
											})}</tbody>
										</table>
									</div>
								)}
								{/* Venue Utilization */}
								{schedule.rich_stats.venue_utilization&&Object.keys(schedule.rich_stats.venue_utilization).length>0&&(
									<div>
										<h5 style={{marginBottom:'0.5rem'}}>Venue Utilization</h5>
										<table className="splm-table" style={{fontSize:'0.85em'}}>
											<thead><tr><th>Venue</th><th>Games</th></tr></thead>
											<tbody>{Object.values(schedule.rich_stats.venue_utilization).map((v,i)=>(
												<tr key={i}><td>{v.name}</td><td>{v.games}</td></tr>
											))}</tbody>
										</table>
									</div>
								)}
							</div>
							{/* Imbalances */}
							{schedule.rich_stats.imbalances?.length>0&&(
								<div style={{marginTop:'0.75rem'}}>
									<h5>Issues &amp; Imbalances</h5>
									{schedule.rich_stats.imbalances.map((iss,i)=>(
										<div key={i} className="splm-alert splm-alert--warning" style={{marginBottom:'0.25rem'}}>⚠️ {iss.message||iss}</div>
									))}
								</div>
							)}
						</details>
					)}
					{/* Simple team stats (always shown) */}
					{schedule.stats&&Object.keys(schedule.stats).length>0&&(
						<div className="splm-card" style={{marginTop:'0.75rem'}}>
							<h4>Team Stats</h4>
							<div className="splm-table-wrapper">
								<table className="splm-table">
									<thead><tr><th>Team</th><th>Games</th><th>Home</th><th>Away</th></tr></thead>
									<tbody>{Object.entries(schedule.stats).sort((a,b)=>a[0].localeCompare(b[0])).map(([t,s])=>(
										<tr key={t}><td>{t}</td><td>{s.total}</td><td>{s.home}</td><td>{s.away}</td></tr>
									))}</tbody>
								</table>
							</div>
						</div>
					)}

					{/* #10: Publish options */}
					<div className="splm-card" style={{marginTop:'0.75rem'}}>
						<h4>Publish to SportsPress</h4>
						<div style={{display:'grid',gridTemplateColumns:'1fr 1fr auto',gap:'0.75rem',alignItems:'end'}}>
							<div><label>Season</label><SLLoad label="Season" items={spS} onLoad={()=>spsg.getSeasons().then(setSpS)} value={pubSeason} onChange={setPubSeason}/></div>
							<div><label>Division</label><SLLoad label="Division" items={spL} onLoad={()=>spsg.getLeagues().then(setSpL)} value={pubLeague} onChange={setPubLeague}/></div>
							<button className="splm-btn splm-btn--primary" onClick={()=>{ if(pubOpts.dry_run||window.confirm(`Publish ${schedule.games?.length||0} events to SportsPress?`)) doPub(); }} disabled={publishing||!pubSeason||!pubLeague}>{publishing?'Publishing…':pubOpts.dry_run?'Dry Run':'Publish'}</button>
						</div>
						<details style={{marginTop:'0.5rem'}}>
							<summary style={{cursor:'pointer',color:'var(--splm-muted)',fontSize:'0.85em'}}>Import Options</summary>
							<div style={{display:'flex',gap:'1rem',flexWrap:'wrap',marginTop:'0.5rem',fontSize:'0.85em'}}>
								<label>Conflict: <select className="splm-select" style={{width:120}} value={pubOpts.conflict_resolution} onChange={e=>setPubOpts(o=>({...o,conflict_resolution:e.target.value}))}>
									<option value="skip">Skip existing</option>
									<option value="overwrite">Overwrite</option>
								</select></label>
								<label>Status: <select className="splm-select" style={{width:110}} value={pubOpts.event_status} onChange={e=>setPubOpts(o=>({...o,event_status:e.target.value}))}>
									<option value="publish">Publish</option>
									<option value="draft">Draft</option>
									<option value="pending">Pending</option>
									<option value="future">Future</option>
								</select></label>
								<label className="splm-checkbox"><input type="checkbox" checked={pubOpts.dry_run} onChange={e=>setPubOpts(o=>({...o,dry_run:e.target.checked}))}/> Dry Run (preview only)</label>
							</div>
						</details>
						{pubProg&&(
							<div style={{marginTop:'0.5rem'}}>
								{pubProg.done
									? <p style={{color:'var(--splm-success)'}}>✅ {pubProg.dry_run?'Dry run: would publish':'Published'} {pubProg.imported} of {pubProg.total} events{pubProg.skipped?` (${pubProg.skipped} skipped)`:''}{!pubProg.dry_run?' — view on the Schedule page.':''}</p>
									: <><p style={{fontSize:'0.85em',marginBottom:'0.25rem'}}>{pubProg.imported} of {pubProg.total} events {pubOpts.dry_run?'checked':'published'}…</p><div style={{height:8,background:'var(--splm-border)',borderRadius:4}}><div style={{width:`${pubProg.total?Math.round(pubProg.imported/pubProg.total*100):0}%`,height:'100%',background:'var(--splm-primary)',borderRadius:4,transition:'width 0.3s'}}/></div></>
								}
							</div>
						)}
					</div>

					{/* #12: TBD replacement with batch support */}
					{placeholders.length>0&&(
						<div className="splm-card" style={{marginTop:'0.75rem',borderLeft:'3px solid var(--splm-warn-amber)'}}>
							<h4>⚠️ {placeholders.length} TBD Team{placeholders.length>1?'s':''} Need Assignment</h4>
							{placeholders.map(ph=>(
								<div key={ph.id} style={{display:'flex',gap:'0.5rem',alignItems:'center',marginBottom:'0.5rem'}}>
									<span style={{minWidth:120}}><span className="splm-badge splm-badge--warning">TBD</span> {ph.name}</span>
									<select className="splm-select" aria-label={`Assign real team for ${ph.name}`} value={replaceMap[ph.id]||''} onChange={e=>setReplaceMap(m=>({...m,[ph.id]:e.target.value}))}>
										<option value="">Assign real team…</option>
										{spTeams.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}
									</select>
									<button className="splm-btn splm-btn--primary" disabled={!replaceMap[ph.id]} onClick={()=>doReplace(ph.id)}>Assign</button>
								</div>
							))}
							{/* #12: batch replace all */}
							{placeholders.length>1&&Object.values(replaceMap).filter(Boolean).length>0&&(
								<button className="splm-btn splm-btn--primary" style={{marginTop:'0.5rem'}} onClick={async()=>{
									const targets = new Set(Object.values(replaceMap).filter(Boolean));
									if (targets.size < Object.values(replaceMap).filter(Boolean).length) {
										if (!window.confirm('Some placeholders are assigned to the same team. Continue?')) return;
									}
									for (const ph of placeholders) {
										if (replaceMap[ph.id]) await doReplace(ph.id);
									}
								}}>Replace All Assigned ({Object.values(replaceMap).filter(Boolean).length})</button>
							)}
						</div>
					)}
					<div className="splm-wizard__actions">
						<button className="splm-btn" onClick={()=>go(0)}>← All Configs</button>
						<button className="splm-btn" onClick={()=>go(1)}>← Edit Settings</button>
						<button className="splm-btn" onClick={()=>{setSchedule(null);setValidation(null);setStep(3);}}>Regenerate</button>
					</div>
				</div>
			)}
		</div>

		</>
	);
}
