import test from 'node:test'
import assert from 'node:assert/strict'

import { highlight, choice, move } from '../../resources/js/student-search-logic.js'

test('the part that matches the typed text is the bold piece, whatever the case', () => {
    assert.deepEqual(highlight('Farah Aqilah', 'AQ'), [{ text: 'Farah ', bold: false }, { text: 'Aq', bold: true }, { text: 'ilah', bold: false }])
    assert.deepEqual(highlight('Farah', 'f'), [{ text: 'F', bold: true }, { text: 'arah', bold: false }])
    assert.deepEqual(highlight('Farah', 'zz'), [{ text: 'Farah', bold: false }])
    assert.deepEqual(highlight('Farah', ''), [{ text: 'Farah', bold: false }])
})

test('choosing a student searches for them, a group or a subject filters by it', () => {
    assert.deepEqual(choice({ type: 'student', name: 'Farah', matric: 'A21EC1' }), { search: 'A21EC1', group: '', subject: '' })
    assert.deepEqual(choice({ type: 'student', name: 'Farah', matric: 'farah@x.my' }), { search: 'Farah', group: '', subject: '' })
    assert.deepEqual(choice({ type: 'group', group_id: 4 }), { search: '', group: '4', subject: '' })
    assert.deepEqual(choice({ type: 'subject', subject_id: 2 }), { search: '', group: '', subject: '2' })
})

test('the arrow keys move the highlight and wrap; none highlighted starts at the first or the last', () => {
    assert.equal(move(-1, 3, 'ArrowDown'), 0)
    assert.equal(move(2, 3, 'ArrowDown'), 0)
    assert.equal(move(-1, 3, 'ArrowUp'), 2)
    assert.equal(move(0, 3, 'ArrowUp'), 2)
    assert.equal(move(1, 3, 'ArrowUp'), 0)
    assert.equal(move(0, 0, 'ArrowDown'), -1)
})
