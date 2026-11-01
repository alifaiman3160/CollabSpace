import test from 'node:test'
import assert from 'node:assert/strict'

import { matches, placeBelow } from '../../resources/js/group-switcher-logic.js'

test('every word typed must be in the text, whatever the case', () => {
    assert.equal(matches('Alpha SECJ3104 Application Development', 'alp'), true)
    assert.equal(matches('Alpha SECJ3104 Application Development', 'alpha secj'), true)
    assert.equal(matches('Alpha SECJ3104 Application Development', 'alpha bcs'), false)
    assert.equal(matches('Alpha', ''), true)
    assert.equal(matches('Alpha', '   '), true)
    assert.equal(matches('Kumpulan Ámber', 'amber'), true)
})

const viewport = { width: 1440, height: 900 }
const card = { width: 300, height: 360 }

test('the card opens under the button, level with its left edge', () => {
    const spot = placeBelow({ left: 700, top: 60, width: 200, height: 40 }, card, viewport)

    assert.deepEqual(spot, { left: 700, top: 108 })
})

test('it is pulled back inside the window on the right and the left', () => {
    assert.equal(placeBelow({ left: 1400, top: 60, width: 100, height: 40 }, card, viewport).left, 1440 - 300 - 12)
    assert.equal(placeBelow({ left: -50, top: 60, width: 100, height: 40 }, card, viewport).left, 12)
})

test('it opens above when there is no room below, and never leaves the window', () => {
    const spot = placeBelow({ left: 100, top: 800, width: 100, height: 40 }, card, viewport)

    assert.equal(spot.top, 800 - 8 - 360)

    for (const top of [0, 300, 700, 880]) {
        const at = placeBelow({ left: 100, top, width: 100, height: 40 }, card, viewport)

        assert.ok(at.top >= 12 && at.top + 360 <= 900 - 12, `top ${top} gave ${at.top}`)
    }
})
