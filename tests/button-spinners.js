// Production button-feedback code (ghoti.js) and the emitted ghotiAsync wrapper
// (ghoti.async.php) against a DOM fixture and a fetch() the test resolves by
// hand. No server, no browser, no network.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');

const root = path.join(__dirname, '..');
const wrapperJs = execFileSync('php', ['-d', 'display_errors=0', '-r',
    'chdir($argv[1]); require "ghoti.php"; require_once "ghoti.async.php"; $GLOBALS["ghoti_async_registry"] = array(); ghoti_async_emit_js();', root],
    {encoding: 'utf8'});

function classList(){
    const set = new Set();
    return {
        add: c => set.add(c), remove: c => set.delete(c), contains: c => set.has(c),
        toggle: (c, on) => { (on === undefined ? !set.has(c) : on) ? set.add(c) : set.delete(c); }
    };
}
function node(tagName, attrs = {}, parentNode = null){
    const el = {
        nodeType: 1, tagName, parentNode, classList: classList(), attrs: Object.assign({}, attrs),
        getAttribute(name){ return name in this.attrs ? this.attrs[name] : null; },
        setAttribute(name, value){ this.attrs[name] = String(value); },
        removeAttribute(name){ delete this.attrs[name]; },
        hasAttribute(name){ return name in this.attrs; }
    };
    if(tagName === 'BUTTON' || tagName === 'INPUT'){ el.disabled = false; el.type = attrs.type || (tagName === 'BUTTON' ? 'submit' : 'text'); }
    (attrs.class || '').split(/\s+/).filter(Boolean).forEach(c => el.classList.add(c));
    return el;
}

const listeners = {};
let timers = [];
let clock = 1000000;
const pending = [];
const html = node('HTML');
const context = vm.createContext({
    document: {
        documentElement: html,
        addEventListener(name, handler){ (listeners[name] = listeners[name] || []).push(handler); },
        getElementById(){ return null; }
    },
    window: {addEventListener(){}, console: {error(){}}},
    console: {error(){}},
    $: () => ({ready(){}}),
    setTimeout(fn){ timers.push(fn); },
    Date: {now: () => clock},
    fetch(){
        return new Promise(resolve => pending.push(result => resolve({json: () => Promise.resolve({ok: true, result})})));
    }
});
vm.runInContext(fs.readFileSync(path.join(root, 'ghoti.js'), 'utf8'), context);
vm.runInContext(wrapperJs, context);
vm.runInContext('function x_work(){ return ghotiAsync("work", arguments); }', context);

//A press: capture listeners fire, then the element's own handler, then the
//event's task ends and queued timers run.
function press(target, handler){
    listeners.click.forEach(fn => fn({target}));
    if(handler){ handler(); }
    const due = timers; timers = [];
    due.forEach(fn => fn());
}
async function reply(value){
    const resolve = pending.shift();
    assert.ok(resolve, 'a request should be in flight');
    resolve(value);
    for(let i = 0; i < 10; i++){ await Promise.resolve(); }
}
const busy = el => el.classList.contains('is-busy');
const x = (...args) => context.x_work(...args);

(async () => {
    // A plain, unstyled <button> spins and disables until its request settles.
    const plain = node('BUTTON', {type: 'button'});
    press(plain, () => x(() => {}));
    assert.ok(busy(plain) && plain.disabled, 'a plain <button> spins while its request runs');
    assert.ok(html.classList.contains('ghotiBusy'), 'the page shows a progress cursor while waiting');
    await reply(true);
    assert.ok(!busy(plain) && !plain.disabled, 'the spinner ends and the button re-enables');
    assert.ok(!html.classList.contains('ghotiBusy'), 'the progress cursor clears');

    // A confirm() that takes several seconds to answer still spins.
    const del = node('BUTTON', {class: 'ghotiButton ghotiButtonDanger'});
    press(del, () => { clock += 5000; x(() => {}); });
    assert.ok(busy(del), 'a delete confirmed after a slow confirm() still spins');
    await reply(true);

    // Clicking a button's icon records the button.
    const iconButton = node('BUTTON', {class: 'ghotiIconButton'});
    const icon = node('IMG', {}, iconButton);
    press(icon, () => x(() => {}));
    assert.ok(busy(iconButton), 'a click on an icon inside a button spins the button');
    await reply(true);

    // Admin menu and navigation links that run script spin too.
    const menuLink = node('A', {href: '#', class: 'dropdown-item', onclick: 'showSiteSettings();'});
    press(menuLink, () => x(() => {}));
    assert.ok(busy(menuLink), 'a script link in a menu spins');
    assert.equal(menuLink.disabled, undefined, 'a link is not given a disabled property');
    await reply(true);
    assert.ok(!busy(menuLink));

    // Text fields and real navigation links are not presses.
    const field = node('INPUT', {type: 'text'});
    press(field, () => x(() => {}));
    assert.ok(!busy(field), 'a text field is not a button');
    await reply(true);
    const external = node('A', {href: 'https://example.test/'});
    press(external, () => x(() => {}));
    assert.ok(!busy(external), 'a real navigation link is not a script button');
    await reply(true);

    // One press, two requests: spins until the LAST one finishes.
    const both = node('BUTTON');
    press(both, () => { x(() => {}); x(() => {}); });
    await reply(true);
    assert.ok(busy(both), 'still busy while the second request runs');
    await reply(true);
    assert.ok(!busy(both), 'done once both have finished');

    // Save, then reload the list from the callback: one continuous spin.
    const save = node('BUTTON');
    press(save, () => x(() => { x(() => {}); }));
    await reply(true);
    assert.ok(busy(save), 'a follow-up request from the callback keeps the same button spinning');
    await reply(true);
    assert.ok(!busy(save), 'the chain ends the spin');

    // A callback that throws cannot leave a button stuck.
    const broken = node('BUTTON');
    press(broken, () => x(() => { throw new Error('callback bug'); }));
    await reply(true);
    assert.ok(!busy(broken) && !broken.disabled, 'a throwing callback still ends the spinner');

    // A button the page had disabled stays disabled afterwards.
    const locked = node('BUTTON');
    press(locked, () => { locked.disabled = true; x(() => {}); });
    await reply(true);
    assert.ok(!busy(locked) && locked.disabled === true, 'a button disabled by its handler stays disabled');

    // A handler that waits briefly (a timer, a file read) is still attributed;
    // background work well after the press is not.
    const later = node('BUTTON');
    press(later);
    clock += 300;
    x(() => {});
    assert.ok(busy(later), 'a request sent 300ms after the press still spins its button');
    await reply(true);
    const stale = node('BUTTON');
    press(stale);
    clock += 2000;
    x(() => {});
    assert.ok(!busy(stale), 'a request two seconds later is background work');
    await reply(true);

    // An Enter-key submit spins the form's submit button.
    const submitButton = node('BUTTON', {type: 'submit'});
    const form = {elements: [node('INPUT', {type: 'text'}), submitButton]};
    listeners.submit.forEach(fn => fn({target: form}));
    x(() => {});
    const due = timers; timers = []; due.forEach(fn => fn());
    assert.ok(busy(submitButton), 'Enter in a form spins its submit button');
    await reply(true);

    // Direct fetch() callers: ghotiBusyBegin's end function is safe to repeat.
    const backup = node('BUTTON');
    const done = context.ghotiBusyBegin(backup);
    assert.ok(busy(backup) && backup.disabled);
    done(); done();
    assert.ok(!busy(backup) && !backup.disabled && !html.classList.contains('ghotiBusy'), 'ending twice is harmless');

    // Uploads have no button: the status line spins.
    const progress = node('P');
    context.ghotiProgressBusy(progress, true);
    assert.ok(progress.classList.contains('ghotiProgressBusy') && progress.getAttribute('aria-busy') === 'true');
    context.ghotiProgressBusy(progress, false);
    assert.ok(!progress.classList.contains('ghotiProgressBusy') && !progress.hasAttribute('aria-busy'));

    assert.equal(pending.length, 0, 'every request was answered');
    console.log('PASS: button spinners - plain buttons, slow confirms, links, multi-request presses, chains, throws, uploads');
})().catch(error => { console.error(error); process.exit(1); });
