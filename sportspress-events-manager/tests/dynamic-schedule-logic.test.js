const L=require('../assets/js/dynamic-schedule.js');
const assert=require('assert');
const base={season:'w26',league:'all',team:'all',status:'all'};
let p=new URLSearchParams('?division=d1&show=upcoming&x=1');
assert.deepStrictEqual(L.valuesFromParams(p,base),{season:'w26',league:'d1',team:'all',status:'upcoming'});
assert(L.hasFilterParams(p)); assert(!L.hasFilterParams(new URLSearchParams('?x=1')));
assert(L.sameValues(base,{...base})); assert(!L.sameValues(base,{...base,team:'5'}));
assert.deepStrictEqual(L.valuesFromParams(new URLSearchParams('?team='),base),base);
let q=new URLSearchParams('?x=1&division=a'); L.writeParams(q,{season:'s',league:'all',team:'7',status:'results'});
assert.strictEqual(q.toString(),'x=1&division=all&season=s&team=7&show=results');
L.writeParams(q,null); assert.strictEqual(q.toString(),'x=1');
const o=L.buildOptions([{id:5,name:'<b>A</b>'}],'id','All teams');
assert.deepStrictEqual(o,[{value:'all',text:'All teams'},{value:'5',text:'<b>A</b>'}]);
assert.strictEqual(L.buildOptions(null,'id','All')[0].value,'all');
assert.strictEqual(L.pickValue(o,5,'all'),'5'); assert.strictEqual(L.pickValue(o,9,'all'),'all');
console.log('logic ok');

// sanitizeUrlValues: invalid shared links must not break the page.
{
  const rendered = { season: 'w26', league: 'all', team: 'all', status: 'all' };
  const opts = { season: ['w26', 'w25'], league: ['all', 'd1'], team: ['all', '5'], status: ['all', 'upcoming', 'results'] };
  const run = (qs) => L.sanitizeUrlValues(new URLSearchParams(qs), rendered, opts);

  let r = run('?season=bogus');
  assert.deepStrictEqual(r.values, rendered);
  assert.deepStrictEqual(r.ignored, ['season']);
  assert(L.sameValues(r.values, rendered), 'nothing differs -> no request');

  r = run('?season=w26&division=d1&show=upcoming');
  assert.deepStrictEqual(r.values, { season: 'w26', league: 'd1', team: 'all', status: 'upcoming' });
  assert.deepStrictEqual(r.ignored, []);

  r = run('?show=nope&team=9&division=d1');
  assert.deepStrictEqual(r.values, { season: 'w26', league: 'd1', team: 'all', status: 'all' });
  assert.deepStrictEqual(r.ignored, ['team', 'show']);

  // A different (valid) season: dependent values pass through for the server.
  r = run('?season=w25&division=old-div&team=77');
  assert.deepStrictEqual(r.values, { season: 'w25', league: 'old-div', team: '77', status: 'all' });
  assert.deepStrictEqual(r.ignored, []);

  // Bogus season: its dependants are still checked against the rendered options.
  r = run('?season=bogus&division=old-div');
  assert.deepStrictEqual(r.values, rendered);
  assert.deepStrictEqual(r.ignored, ['season', 'division']);

  assert.deepStrictEqual(run('?x=1').ignored, []);
  console.log('sanitizeUrlValues ok');
}

// statusAfterSeasonChange: a past season must not inherit "Upcoming".
{
  assert.strictEqual(L.statusAfterSeasonChange('upcoming', true), 'all');
  assert.strictEqual(L.statusAfterSeasonChange('upcoming', false), 'upcoming');
  assert.strictEqual(L.statusAfterSeasonChange('results', true), 'results');
  assert.strictEqual(L.statusAfterSeasonChange('all', true), 'all');
  console.log('statusAfterSeasonChange ok');
}

// isAccepted: the per-field rule behind sanitizeUrlValues.
{
  const opts = { season: ['w26'], league: ['all', 'd1'], team: ['all'], status: ['all'] };
  const f = (key) => L.FIELDS.find((x) => x.key === key);
  assert(L.isAccepted(f('season'), 'w26', opts, false));
  assert(!L.isAccepted(f('season'), 'zz', opts, true), 'season is never accepted unchecked');
  assert(!L.isAccepted(f('league'), 'zz', opts, false));
  assert(L.isAccepted(f('league'), 'zz', opts, true), 'dependent value after a season move');
  assert(L.isAccepted(f('team'), '77', opts, true));
  assert(!L.isAccepted(f('status'), 'nope', opts, true));
  assert(!L.isAccepted(f('league'), '', opts, true), 'empty is not a value');
  console.log('isAccepted ok');
}
