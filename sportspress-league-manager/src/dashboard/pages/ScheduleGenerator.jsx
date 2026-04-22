import { useState, useEffect, useCallback } from '@wordpress/element';
import { spsg } from '../lib/api';
import { fetchScheduleConfig, rolloverPreview, rolloverExecute } from '../lib/api';

const DAYS = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
const DL = {monday:'Mon',tuesday:'Tue',wednesday:'Wed',thursday:'Thu',friday:'Fri',saturday:'Sat',sunday:'Sun'};
const blank = () => ({name:'',start_date:'',end_date:'',playing_days:['friday','sunday'],games_per_team:20,match_length:60,matchup_style:'single_round_robin',divisions:[],venues:[],time_slots:{},blackout_dates:'',advanced:{b2b_pairs:[],inter_division:{},venue_prefs:{}}});
let _tbd = 0;
const mkId = (p) => `${p}_${Date.now()}_${Math.random().toString(36).slice(2,6)}`;

function Tbd({name}) { if (!name) return null; return name.startsWith('TBD') ? <><span className="splm-badge splm-badge--warning">TBD</span> {name}</> : name; }

function Cap({cfg}) {
	const tt = cfg.divisions.reduce((s,d) => s+d.teams.length, 0), need = Math.ceil(tt*cfg.games_per_team/2);
	if (!cfg.start_date||!cfg.end_date||!cfg.venues.length) return null;
	const s = new Date(cfg.start_date), e = new Date(cfg.end_date); let pd = 0;
	for (let d = new Date(s); d <= e; d.setDate(d.getDate()+1)) { if (cfg.playing_days.includes(DAYS[(d.getDay()+6)%7])) pd++; }
	const sc = cfg.playing_days.map(d => (cfg.time_slots[d]||[]).length);
	const avg = sc.length ? sc.reduce((a,b) => a+b,0)/sc.length : 0;
	const avail = Math.floor(pd*avg*cfg.venues.length), pct = avail ? Math.round(need/avail*100) : 999;
	const col = pct>95||need>avail ? '#d63638' : pct>80 ? '#dba617' : '#00a32a';
	return <div className="splm-card" style={{marginTop:'1rem'}}><strong>Capacity:</strong> {need} needed / {avail} available ({pct}%)<div style={{height:8,background:'#ddd',borderRadius:4,marginTop:6}}><div style={{width:`${Math.min(pct,100)}%`,height:'100%',background:col,borderRadius:4}}/></div></div>;
}

function VenueSel({venues,selected,onLoad,onChange}) {
	useEffect(() => { if (!venues.length) onLoad(); }, []);
	const tog = id => onChange(selected.includes(id) ? selected.filter(v=>v!==id) : [...selected,id]);
	return <div><label>Venues ({selected.length} selected)</label>{!venues.length&&<p className="splm-loading">Loading…</p>}<div className="splm-checkbox-grid">{venues.map(v=><label key={v.id} className="splm-checkbox"><input type="checkbox" checked={selected.includes(v.id)} onChange={()=>tog(v.id)}/> {v.name}</label>)}</div></div>;
}

function SLLoad({items,onLoad,value,onChange}) {
	useEffect(() => { if (!items.length) onLoad(); }, []);
	return <select className="splm-select" value={value} onChange={e=>onChange(e.target.value)}><option value="">Select…</option>{items.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select>;
}

export default function ScheduleGenerator() {
	const [step,setStep]=useState(0),[configs,setConfigs]=useState([]),[cfg,setCfg]=useState(blank());
	const [configId,setConfigId]=useState(null),[loading,setLoading]=useState(false),[error,setError]=useState('');
	const [spL,setSpL]=useState([]),[spV,setSpV]=useState([]),[spS,setSpS]=useState([]);
	const [validation,setValidation]=useState(null),[generating,setGenerating]=useState(false),[genProg,setGenProg]=useState(null);
	const [schedule,setSchedule]=useState(null),[divF,setDivF]=useState('');
	const [pubProg,setPubProg]=useState(null),[publishing,setPublishing]=useState(false);
	const [pubSeason,setPubSeason]=useState(''),[pubLeague,setPubLeague]=useState('');
	const [importLg,setImportLg]=useState('');
	const [rc,setRc]=useState(null),[rFrom,setRFrom]=useState(''),[rTo,setRTo]=useState('');
	const [rPrev,setRPrev]=useState(null),[rSel,setRSel]=useState({}),[rLoad,setRLoad]=useState(false);
	const [rMsg,setRMsg]=useState(''),[rErr,setRErr]=useState('');

	const loadConfigs = useCallback(() => { setLoading(true); spsg.listConfigs().then(setConfigs).catch(()=>setError('Failed to load configs')).finally(()=>setLoading(false)); }, []);
	useEffect(() => { loadConfigs(); fetchScheduleConfig().then(setRc).catch(()=>{}); }, []);

	const up = patch => setCfg(p => ({...p,...patch}));
	const togDay = day => up({playing_days: cfg.playing_days.includes(day) ? cfg.playing_days.filter(d=>d!==day) : [...cfg.playing_days,day]});
	const save = async () => { if (configId) { await spsg.updateConfig(configId,cfg); } else { const r = await spsg.createConfig(cfg); setConfigId(r.id); return r.id; } return configId; };
	const go = async t => { if (t>step&&step>=1) { try { await save(); } catch { setError('Failed to save'); return; } } setError(''); setStep(t); };

	const doImport = async () => {
		if (!spL.length) { setSpL(await spsg.getLeagues()); return; }
		const lg = spL.find(l => String(l.id)===importLg);
		if (!lg?.teams) return;
		up({divisions:[...cfg.divisions,{id:mkId('div'),name:lg.name,teams:lg.teams.map(t=>({id:mkId('team'),name:t.name,is_tbd:false}))}]});
		setImportLg('');
	};
	const addDiv = () => up({divisions:[...cfg.divisions,{id:mkId('div'),name:`Division ${cfg.divisions.length+1}`,teams:[]}]});
	const rmDiv = id => up({divisions:cfg.divisions.filter(d=>d.id!==id)});
	const upDiv = (id,p) => up({divisions:cfg.divisions.map(d=>d.id===id?{...d,...p}:d)});
	const addTeam = (did,n='',tbd=false) => { const nm=tbd?`TBD ${++_tbd}`:n||'New Team'; upDiv(did,{teams:[...(cfg.divisions.find(d=>d.id===did)?.teams||[]),{id:mkId('team'),name:nm,is_tbd:tbd}]}); };
	const rmTeam = (did,tid) => { const d=cfg.divisions.find(x=>x.id===did); if(d) upDiv(did,{teams:d.teams.filter(t=>t.id!==tid)}); };
	const upTeam = (did,tid,nm) => { const d=cfg.divisions.find(x=>x.id===did); if(d) upDiv(did,{teams:d.teams.map(t=>t.id===tid?{...t,name:nm}:t)}); };

	const doGen = async () => {
		setGenerating(true); setError('');
		try {
			const id = await save();
			const result = await spsg.generate(id||configId);
			// Generation is synchronous — result contains schedule_id, game_count, and games
			setSchedule({ id: result.schedule_id, games: result.games || [], stats: result.stats || {} });
			setGenerating(false); setStep(4);
		} catch(e) { setError(e?.message||'Failed to generate'); setGenerating(false); }
	};
	const doCancel = () => { spsg.cancel().catch(()=>{}); setGenerating(false); };

	const doPub = async () => {
		if (!schedule?.id||!pubSeason||!pubLeague) return;
		setPublishing(true); setPubProg({offset:0,total:schedule.games?.length||0}); let off=0;
		try { while(true) { const r = await spsg.publish(schedule.id,pubSeason,pubLeague,off,50); off+=50; setPubProg({offset:off,total:r.total||schedule.games?.length||0}); if(r.remaining===0) break; } setPubProg(p=>({...p,done:true})); } catch { setError('Publish failed'); }
		setPublishing(false);
	};

	const rSeasons = rc?.seasons||[];
	const g = {display:'grid',gridTemplateColumns:'1fr 1fr',gap:'0.75rem'};
	const f = {display:'flex',gap:'0.5rem'};

	return (
		<>
		<div className="splm-wizard">
			<h2>Schedule Generator</h2>
			{error && <div className="splm-alert splm-alert--warning">{error}</div>}
			{step>0&&step<4&&<div style={{...f,marginBottom:'1rem'}}>{['Teams & Season','Rinks & Times','Review & Generate'].map((l,i)=><button key={i} className={`splm-btn${step===i+1?' splm-btn--primary':''}`} onClick={()=>go(i+1)}>{i+1}. {l}</button>)}</div>}

			{step===0&&<div className="splm-wizard__step">
				<div className="splm-card"><h3>Saved Configurations</h3>
					{loading&&<div className="splm-loading">Loading…</div>}
					{!loading&&!configs.length&&<p className="splm-muted">No saved configs yet.</p>}
					{configs.length>0&&<div className="splm-table-wrapper"><table className="splm-table"><thead><tr><th>Name</th><th>Updated</th><th>Divisions</th><th>Teams</th><th></th></tr></thead><tbody>{configs.map(c=><tr key={c.id}><td>{c.name}</td><td>{c.updated_at}</td><td>{c.division_count}</td><td>{c.team_count}</td><td><button className="splm-btn" onClick={()=>spsg.getConfig(c.id).then(d=>{setCfg(d);setConfigId(c.id);setStep(1);})}>Load</button></td></tr>)}</tbody></table></div>}
				</div>
				<div className="splm-wizard__actions">
					{configs.length>0&&<button className="splm-btn splm-btn--primary" onClick={()=>{const c=configs[0];spsg.cloneConfig(c.id,`${c.name} (copy)`).then(d=>{setCfg(d);setConfigId(d.id);setStep(1);}).catch(()=>setError('Clone failed'));}}>Start from {configs[0]?.name}</button>}
					<button className="splm-btn" onClick={()=>{setCfg(blank());setConfigId(null);setStep(1);}}>Start Fresh</button>
				</div>
			</div>}

			{step===1&&<div className="splm-wizard__step">
				<div className="splm-card"><h3>Who &amp; When</h3>
					<div style={g}>
						<div style={{gridColumn:'1/-1'}}><label>Config Name</label><input className="splm-select" value={cfg.name} onChange={e=>up({name:e.target.value})}/></div>
						<div><label>Start Date</label><input type="date" className="splm-select" value={cfg.start_date} onChange={e=>up({start_date:e.target.value})}/></div>
						<div><label>End Date</label><input type="date" className="splm-select" value={cfg.end_date} onChange={e=>up({end_date:e.target.value})}/></div>
						<div><label>Games per Team</label><input type="number" className="splm-select" min="1" value={cfg.games_per_team} onChange={e=>up({games_per_team:parseInt(e.target.value,10)||0})}/></div>
						<div><label>Match Length (min)</label><input type="number" className="splm-select" min="1" value={cfg.match_length} onChange={e=>up({match_length:parseInt(e.target.value,10)||60})}/></div>
						<div><label>Matchup Style</label><select className="splm-select" value={cfg.matchup_style} onChange={e=>up({matchup_style:e.target.value})}><option value="single_round_robin">Single Round Robin</option><option value="double_round_robin">Double Round Robin</option></select></div>
					</div>
					<div style={{marginTop:'0.75rem'}}><label>Playing Days</label><div style={{...f,flexWrap:'wrap',gap:'0.75rem'}}>{DAYS.map(d=><label key={d} className="splm-checkbox"><input type="checkbox" checked={cfg.playing_days.includes(d)} onChange={()=>togDay(d)}/> {DL[d]}</label>)}</div></div>
				</div>
				<div className="splm-card"><h3>Divisions</h3>
					<div style={{...f,marginBottom:'0.75rem'}}>
						<button className="splm-btn" onClick={addDiv}>Add Division</button>
						<button className="splm-btn" onClick={doImport}>{spL.length?'Import Selected':'Import from SportsPress'}</button>
						{spL.length>0&&<select className="splm-select" value={importLg} onChange={e=>setImportLg(e.target.value)}><option value="">Pick a league…</option>{spL.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select>}
					</div>
					{cfg.divisions.map(div=><div key={div.id} className="splm-card" style={{marginBottom:'0.75rem'}}>
						<div style={{...f,alignItems:'center',marginBottom:'0.5rem'}}><input className="splm-select" value={div.name} onChange={e=>upDiv(div.id,{name:e.target.value})} style={{flex:1}}/><button className="splm-btn splm-btn--danger" onClick={()=>rmDiv(div.id)}>✕</button></div>
						{div.teams.map(t=><div key={t.id} style={{...f,alignItems:'center',marginBottom:'0.25rem',paddingLeft:'1rem'}}>{t.is_tbd&&<span className="splm-badge splm-badge--warning">TBD</span>}<input className="splm-select" value={t.name} onChange={e=>upTeam(div.id,t.id,e.target.value)} style={{flex:1}}/><button className="splm-btn splm-btn--danger" onClick={()=>rmTeam(div.id,t.id)} style={{padding:'0.2rem 0.5rem'}}>✕</button></div>)}
						<div style={{...f,marginTop:'0.5rem',paddingLeft:'1rem'}}><button className="splm-btn" onClick={()=>addTeam(div.id)}>Add Team</button><button className="splm-btn" onClick={()=>addTeam(div.id,'',true)}>Add TBD Team</button></div>
					</div>)}
				</div>
				<div className="splm-wizard__actions"><button className="splm-btn" onClick={()=>setStep(0)}>Back</button><button className="splm-btn splm-btn--primary" onClick={()=>go(2)}>Next: Rinks &amp; Times →</button></div>
			</div>}

			{step===2&&<div className="splm-wizard__step">
				<div className="splm-card"><h3>Where</h3><VenueSel venues={spV} selected={cfg.venues} onLoad={()=>spsg.getVenues().then(setSpV)} onChange={v=>up({venues:v})}/></div>
				<div className="splm-card"><h3>Time Slots per Playing Day</h3>
					{cfg.playing_days.map(day=><div key={day} style={{marginBottom:'0.75rem'}}><strong>{DL[day]||day}</strong>
						{(cfg.time_slots[day]||[]).map((t,i)=><span key={i} style={{display:'inline-flex',gap:'0.25rem',alignItems:'center',marginLeft:'0.5rem'}}>
							<input type="time" className="splm-select" value={t} onChange={e=>{const sl={...cfg.time_slots};sl[day]=[...(sl[day]||[])];sl[day][i]=e.target.value;up({time_slots:sl});}}/>
							<button className="splm-btn splm-btn--danger" style={{padding:'0.1rem 0.4rem'}} onClick={()=>{const sl={...cfg.time_slots};sl[day]=sl[day].filter((_,j)=>j!==i);up({time_slots:sl});}}>✕</button>
						</span>)}
						<button className="splm-btn" style={{marginLeft:'0.5rem'}} onClick={()=>{const sl={...cfg.time_slots};sl[day]=[...(sl[day]||[]),'19:00'];up({time_slots:sl});}}>+ Time</button>
					</div>)}
					{!cfg.playing_days.length&&<p className="splm-muted">Select playing days in Step 1 first.</p>}
				</div>
				<div className="splm-card"><h3>Blackout Dates</h3><textarea className="splm-textarea" rows="3" placeholder="One date per line (YYYY-MM-DD)" value={cfg.blackout_dates} onChange={e=>up({blackout_dates:e.target.value})}/></div>
				<Cap cfg={cfg}/>
				<div className="splm-wizard__actions"><button className="splm-btn" onClick={()=>setStep(1)}>← Back</button><button className="splm-btn splm-btn--primary" onClick={()=>go(3)}>Next: Review →</button></div>
			</div>}

			{step===3&&<div className="splm-wizard__step">
				<div style={{display:'grid',gridTemplateColumns:'1fr 1fr 1fr',gap:'0.75rem'}}>
					<div className="splm-card"><h4>Teams</h4><p>{cfg.divisions.reduce((s,d)=>s+d.teams.length,0)} teams in {cfg.divisions.length} divisions</p></div>
					<div className="splm-card"><h4>Season</h4><p>{cfg.start_date} → {cfg.end_date}<br/>{cfg.games_per_team} games/team</p></div>
					<div className="splm-card"><h4>Venues</h4><p>{cfg.venues.length} venues, {Object.values(cfg.time_slots).reduce((s,a)=>s+a.length,0)} slots</p></div>
				</div>
				<div className="splm-card" style={{marginTop:'0.75rem'}}>
					<button className="splm-btn" onClick={async()=>{try{const id=await save();setValidation(await spsg.validateConfig(id||configId));}catch{setValidation({errors:['Validation failed']});}}}>Validate Config</button>
					{validation&&<div style={{marginTop:'0.5rem'}}>
						{(validation.errors||[]).map((e,i)=><div key={i} className="splm-alert splm-alert--warning" style={{background:'#fcf0f0',borderColor:'#d63638',marginBottom:'0.25rem'}}>❌ {e}</div>)}
						{(validation.warnings||[]).map((w,i)=><div key={i} className="splm-alert splm-alert--warning" style={{marginBottom:'0.25rem'}}>⚠️ {w}</div>)}
						{!validation.errors?.length&&!validation.warnings?.length&&<p style={{color:'#00a32a'}}>✅ Config looks good!</p>}
					</div>}
				</div>
				<details className="splm-card" style={{marginTop:'0.75rem'}}><summary style={{cursor:'pointer',fontWeight:600}}>Advanced Options</summary><div style={{marginTop:'0.75rem'}}>
					<label>Back-to-back avoidance pairs (comma-separated per line)</label>
					<textarea className="splm-textarea" rows="2" value={(cfg.advanced.b2b_pairs||[]).map(p=>p.join(',')).join('\n')} onChange={e=>up({advanced:{...cfg.advanced,b2b_pairs:e.target.value.split('\n').filter(Boolean).map(l=>l.split(',').map(s=>s.trim()))}})}/>
					<label style={{marginTop:'0.5rem',display:'block'}}>Inter-division games</label>
					{cfg.divisions.length>1&&cfg.divisions.map((d1,i)=>cfg.divisions.slice(i+1).map(d2=><div key={`${d1.id}-${d2.id}`} style={{display:'flex',gap:'0.5rem',alignItems:'center',marginBottom:'0.25rem'}}><span>{d1.name} ↔ {d2.name}</span><input type="number" className="splm-select" min="0" style={{width:80}} value={cfg.advanced.inter_division[`${d1.id}:${d2.id}`]||0} onChange={e=>up({advanced:{...cfg.advanced,inter_division:{...cfg.advanced.inter_division,[`${d1.id}:${d2.id}`]:parseInt(e.target.value,10)||0}}})}/></div>))}
					<label style={{marginTop:'0.5rem',display:'block'}}>Home venue preferences</label>
					{cfg.divisions.flatMap(d=>d.teams).map(t=><div key={t.id} style={{display:'flex',gap:'0.5rem',alignItems:'center',marginBottom:'0.25rem'}}><span style={{minWidth:120}}>{t.name}</span><select className="splm-select" value={cfg.advanced.venue_prefs[t.id]||''} onChange={e=>up({advanced:{...cfg.advanced,venue_prefs:{...cfg.advanced.venue_prefs,[t.id]:e.target.value}}})}><option value="">Any</option>{spV.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}</select></div>)}
				</div></details>
				{generating&&<div className="splm-card" style={{marginTop:'0.75rem'}}><strong>Generating…</strong> {genProg?.percent?`${genProg.percent}%`:''}<div style={{height:8,background:'#ddd',borderRadius:4,marginTop:6}}><div style={{width:`${genProg?.percent||5}%`,height:'100%',background:'#2271b1',borderRadius:4,transition:'width 0.3s'}}/></div><button className="splm-btn splm-btn--danger" style={{marginTop:'0.5rem'}} onClick={doCancel}>Cancel</button></div>}
				<div className="splm-wizard__actions"><button className="splm-btn" onClick={()=>setStep(2)}>← Back</button><button className="splm-btn splm-btn--primary" onClick={doGen} disabled={generating||(validation?.errors?.length>0)}>{generating?'Generating…':'Generate Schedule'}</button></div>
			</div>}

			{step===4&&schedule&&<div className="splm-wizard__step">
				<div className="splm-card"><h3>Schedule Preview ({schedule.games?.length||0} games)</h3>
					<div style={{marginBottom:'0.5rem'}}><select className="splm-select" value={divF} onChange={e=>setDivF(e.target.value)}><option value="">All Divisions</option>{cfg.divisions.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
					<div className="splm-table-wrapper"><table className="splm-table"><thead><tr><th>Date</th><th>Time</th><th>Home</th><th>Away</th><th>Venue</th></tr></thead><tbody>
						{(schedule.games||[]).filter(x=>!divF||x.division_id===divF).map((x,i)=><tr key={i}><td>{x.date}</td><td>{x.time}</td><td><Tbd name={x.home}/></td><td><Tbd name={x.away}/></td><td>{x.venue}</td></tr>)}
					</tbody></table></div>
				</div>
				{schedule.stats&&<div className="splm-card" style={{marginTop:'0.75rem'}}><h4>Stats</h4><div className="splm-table-wrapper"><table className="splm-table"><thead><tr><th>Team</th><th>Games</th><th>Home</th><th>Away</th></tr></thead><tbody>{Object.entries(schedule.stats).map(([t,s])=><tr key={t}><td>{t}</td><td>{s.total}</td><td>{s.home}</td><td>{s.away}</td></tr>)}</tbody></table></div></div>}
				<div className="splm-card" style={{marginTop:'0.75rem'}}><h4>Publish to SportsPress</h4>
					<div style={{display:'grid',gridTemplateColumns:'1fr 1fr auto',gap:'0.75rem',alignItems:'end'}}>
						<div><label>Season</label><SLLoad items={spS} onLoad={()=>spsg.getSeasons().then(setSpS)} value={pubSeason} onChange={setPubSeason}/></div>
						<div><label>League</label><SLLoad items={spL} onLoad={()=>spsg.getLeagues().then(setSpL)} value={pubLeague} onChange={setPubLeague}/></div>
						<button className="splm-btn splm-btn--primary" onClick={doPub} disabled={publishing||!pubSeason||!pubLeague}>{publishing?'Publishing…':'Publish'}</button>
					</div>
					{pubProg&&<div style={{marginTop:'0.5rem'}}>{pubProg.done?<p style={{color:'#00a32a'}}>✅ Published {pubProg.total} events!</p>:<div style={{height:8,background:'#ddd',borderRadius:4}}><div style={{width:`${pubProg.total?Math.round(pubProg.offset/pubProg.total*100):0}%`,height:'100%',background:'#2271b1',borderRadius:4,transition:'width 0.3s'}}/></div>}</div>}
				</div>
				<div className="splm-wizard__actions"><button className="splm-btn" onClick={()=>setStep(1)}>← Back to Settings</button><button className="splm-btn" onClick={()=>{setSchedule(null);setValidation(null);setStep(3);}}>Regenerate</button></div>
			</div>}
		</div>

		{ rc && (
			<div className="splm-wizard" style={ { marginTop: '2rem' } }>
				<h2>Season Rollover</h2>
				<p className="splm-muted">Move players who didn't register for the new season from current team to past teams.</p>

				{ rErr && <div className="splm-alert splm-alert--warning">{ rErr }</div> }
				{ rMsg && <div className="splm-card"><p>{ rMsg }</p></div> }

				<div className="splm-card">
					<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: '0.75rem', alignItems: 'end' } }>
						<div>
							<label>From Season</label>
							<select className="splm-select" value={ rFrom } onChange={ ( e ) => setRFrom( e.target.value ) }>
								<option value="">Select…</option>
								{ rSeasons.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.name }</option> ) }
							</select>
						</div>
						<div>
							<label>To Season</label>
							<select className="splm-select" value={ rTo } onChange={ ( e ) => setRTo( e.target.value ) }>
								<option value="">Select…</option>
								{ rSeasons.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.name }</option> ) }
							</select>
						</div>
						<button className="splm-btn splm-btn--primary" onClick={ () => {
							setRErr( '' ); setRMsg( '' ); setRLoad( true );
							rolloverPreview( rFrom, rTo )
								.then( ( data ) => {
									setRPrev( data );
									const sel = {};
									( data.not_returning || [] ).forEach( ( p ) => { sel[ p.id ] = true; } );
									setRSel( sel );
								} )
								.catch( () => setRErr( 'Failed to load preview' ) )
								.finally( () => setRLoad( false ) );
						} } disabled={ rLoad || ! rFrom || ! rTo }>
							{ rLoad ? 'Loading…' : 'Preview' }
						</button>
					</div>
				</div>

				{ rPrev && (
					<div className="splm-card">
						<p><strong>{ rPrev.returning_count || 0 }</strong> returning · <strong>{ rPrev.total_not_returning || 0 }</strong> not returning</p>
						{ ( rPrev.not_returning || [] ).map( ( group ) => {
							const allChecked = group.players.every( ( p ) => rSel[ p.id ] );
							return (
								<details key={ group.team_id } style={ { marginBottom: '0.5rem' } }>
									<summary style={ { cursor: 'pointer', fontWeight: 600 } }>
										<label className="splm-checkbox" style={ { display: 'inline' } } onClick={ ( e ) => e.stopPropagation() }>
											<input type="checkbox" checked={ allChecked } onChange={ ( e ) => {
												setRSel( ( prev ) => {
													const next = { ...prev };
													group.players.forEach( ( p ) => { next[ p.id ] = e.target.checked; } );
													return next;
												} );
											} } />
										</label>
										{ group.team } ({ group.players.length })
									</summary>
									<div style={ { paddingLeft: '2rem' } }>
										{ group.players.map( ( p ) => (
											<label key={ p.id } className="splm-checkbox" style={ { display: 'block' } }>
												<input type="checkbox" checked={ !! rSel[ p.id ] } onChange={ ( e ) => setRSel( ( prev ) => ( { ...prev, [ p.id ]: e.target.checked } ) ) } />
												{ p.name }
											</label>
										) ) }
									</div>
								</details>
							);
						} ) }
						<button className="splm-btn splm-btn--danger" style={ { marginTop: '1rem' } } onClick={ () => {
							const ids = Object.keys( rSel ).filter( ( k ) => rSel[ k ] ).map( Number );
							if ( ! ids.length ) return;
							setRErr( '' ); setRLoad( true );
							rolloverExecute( rFrom, rTo, ids )
								.then( ( data ) => { setRMsg( `✅ ${ data.count || ids.length } player(s) moved to past teams.` ); setRPrev( null ); } )
								.catch( () => setRErr( 'Failed to execute rollover' ) )
								.finally( () => setRLoad( false ) );
						} } disabled={ rLoad || ! Object.values( rSel ).some( Boolean ) }>
							{ rLoad ? 'Processing…' : 'Move Selected to Past Teams' }
						</button>
					</div>
				) }
			</div>
		) }
		</>
	);
}