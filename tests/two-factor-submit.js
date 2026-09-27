// Production 2FA form handlers with a DOM fixture; no server, no real session.
// Guards the double submit that lost freshly established sessions: a pasted
// code plus Enter sent two verifyTwoFactor requests, and the loser overwrote
// the regenerated session cookie.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

let nodes;
function element(extra){
    return Object.assign({
        handlers: {}, disabled: false, value: '', textContent: '', hidden: true,
        addEventListener(name, handler){ this.handlers[name] = handler; },
        focus(){ this.focused = true; }
    }, extra);
}
function freshForm(){
    const submit = element({textContent: 'Sign in'});
    nodes = {
        twoFactorCode: element(),
        twoFactorFeedback: element(),
        twoFactorForm: element({querySelector: selector => selector === 'button[type="submit"]' ? submit : null})
    };
    return submit;
}

const verifyCalls = [];
const cancelCalls = [];
let established = 0;
let timers = 0;
const context = vm.createContext({
    document: {getElementById: id => nodes[id] || null},
    window: {setTimeout(){ timers++; }},
    $: () => ({html(){}, ready(){}}),
    showPopup(){}, cancelPopup(){}, popupLogin(){},
    x_verifyTwoFactor(code, callback){ verifyCalls.push({code, callback}); },
    x_cancelTwoFactor(callback){ cancelCalls.push(callback); }
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../mod/login/login.js'), 'utf8'), context);
context.loginEstablished = () => { established++; };

// Paste then Enter: the input handler and the submit handler both fire.
let submit = freshForm();
context.loginShowTwoFactorForm();
nodes.twoFactorCode.value = '042931';
nodes.twoFactorCode.handlers.input();
nodes.twoFactorForm.handlers.submit({preventDefault(){}});
context.submitTwoFactor();
assert.equal(verifyCalls.length, 1, '2FA must submit only once while a request is in flight');
assert.equal(verifyCalls[0].code, '042931');
assert.equal(nodes.twoFactorCode.disabled, true, 'the code field locks while the request runs');
assert.equal(submit.disabled, true, 'the submit button locks while the request runs');

// Cancelling mid-flight would race the same way, so it waits.
context.cancelTwoFactor();
assert.equal(cancelCalls.length, 0, 'cancel does not race an in-flight code');

// Success establishes the session once and keeps the lock.
verifyCalls[0].callback(2);
assert.equal(established, 1, 'success signs in exactly once');
context.submitTwoFactor();
assert.equal(verifyCalls.length, 1, 'nothing may resubmit after success');

// A wrong code releases the lock for another try.
submit = freshForm();
context.loginShowTwoFactorForm();
assert.equal(context.twoFactorRequestPending, false, 'a new form starts unlocked');
nodes.twoFactorCode.value = '111111';
context.submitTwoFactor();
verifyCalls[1].callback(0);
assert.equal(nodes.twoFactorCode.disabled, false, 'a wrong code unlocks the field');
assert.equal(submit.disabled, false, 'a wrong code unlocks the button');
assert.equal(submit.textContent, 'Sign in');
assert.equal(nodes.twoFactorCode.value, '', 'a wrong code clears the field');
assert.match(nodes.twoFactorFeedback.textContent, /not right/);
nodes.twoFactorCode.value = '222222';
context.submitTwoFactor();
assert.equal(verifyCalls.length, 3, 'another attempt is allowed after a wrong code');

// A terminal refusal also unlocks, then heads back to the login form.
verifyCalls[2].callback('That code has expired. Start again.');
assert.equal(context.twoFactorRequestPending, false);
assert.equal(timers, 1, 'an expired attempt returns to the login form');

// Idle, cancel goes through.
context.cancelTwoFactor();
assert.equal(cancelCalls.length, 1, 'cancel works when nothing is in flight');

console.log('PASS: 2FA double submit, lock release on failure, no resubmit after success');
