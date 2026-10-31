/*
 * "Refresh suggestions" on the group marking page.
 *
 * Works through the group one student at a time. Each student is one small
 * request to the server, which makes at most one AI call and answers well
 * inside half a minute, so nothing here waits on a whole group inside a single
 * request. If the AI service says to slow down (a 429), the server does not sleep:
 * it says how long, and this waits that long in the page and asks again, up to a
 * few times, before marking that student failed with a Retry button. A failed
 * student still has their suggestions from the numbers; only the AI part is missing.
 *
 * Nothing is held on the server between students, so the lecturer can leave the
 * page at any point and come back: whoever was finished stays finished.
 *
 * Registered on Alpine's init event, because Livewire supplies the only Alpine
 * on the page (see app.js).
 */

const CLIENT_TIMEOUT_MS = 40000   // longer than the server's own bound, so the server's answer wins

export default (config) => ({
    items: config.students.map((student) => ({
        id: student.id,
        name: student.name,
        url: student.url,
        // waiting | working | pausing | done | failed | skipped
        state: student.approved ? 'skipped' : 'waiting',
        note: student.approved ? 'Approved, so left as it is.' : '',
        attempt: 0,
        wait: 0,
        counts: null,
        ruleOnly: false,
    })),

    token: config.token,
    maxAttempts: config.maxAttempts ?? 3,
    waitCap: config.waitCap ?? 60,

    running: false,
    started: false,
    stopped: false,
    current: null,

    get total() {
        return this.items.length
    },

    get finishedCount() {
        return this.items.filter((item) => ['done', 'failed', 'skipped'].includes(item.state)).length
    },

    get problems() {
        return this.items.filter((item) => item.state === 'failed').length
    },

    get percent() {
        return this.total === 0 ? 0 : Math.round((this.finishedCount / this.total) * 100)
    },

    /** "Working on it... 2 of 4: Farah Aqilah Roslan", "Done", or "Done, with 1 problem". */
    get headline() {
        if (this.running && this.current !== null) {
            const position = this.items.findIndex((item) => item.id === this.current) + 1

            return `Working on it... ${position} of ${this.total}: ${this.items[position - 1].name}`
        }

        if (this.running) return 'Working on it...'

        if (this.stopped) return `Stopped after ${this.finishedCount} of ${this.total}`

        if (this.problems > 0) {
            return `Done, with ${this.problems} ${this.problems === 1 ? 'problem' : 'problems'}`
        }

        return 'Done'
    },

    async start() {
        if (this.running) return

        this.running = true
        this.started = true
        this.stopped = false

        // A fresh pass over everyone except those whose marks are approved.
        this.items.forEach((item) => {
            if (item.state !== 'skipped') {
                Object.assign(item, { state: 'waiting', note: '', attempt: 0, wait: 0, counts: null, ruleOnly: false })
            }
        })

        for (const item of this.items) {
            if (this.stopped) break
            await this.work(item)
        }

        this.current = null
        this.running = false
    },

    stop() {
        this.stopped = true
    },

    /** Try one student again, on their own. */
    async retry(item) {
        if (this.running) return

        this.running = true
        this.stopped = false
        await this.work(item)
        this.current = null
        this.running = false
    },

    async work(item) {
        if (item.state === 'skipped') return

        for (let attempt = 1; attempt <= this.maxAttempts; attempt++) {
            if (this.stopped) {
                item.state = 'waiting'
                return
            }

            this.current = item.id
            Object.assign(item, { state: 'working', attempt, note: '' })

            let result

            try {
                result = await this.ask(item)
            } catch (error) {
                Object.assign(item, { state: 'failed', note: error.message })

                return
            }

            if (result.status === 'rate_limited') {
                if (attempt === this.maxAttempts) {
                    Object.assign(item, {
                        state: 'failed',
                        ruleOnly: true,
                        note: `The AI is still busy. ${item.name} keeps the suggestions from the numbers. Try again in a minute.`,
                    })

                    return
                }

                await this.pause(item, Math.min(result.retry_after || 5, this.waitCap), attempt)

                continue
            }

            this.finish(item, result)

            return
        }
    },

    /** Wait out a rate limit in the page, with a countdown. */
    async pause(item, seconds, attempt) {
        item.state = 'pausing'

        for (let left = seconds; left > 0; left--) {
            if (this.stopped) return

            item.wait = left
            item.note = `The AI is busy. Trying ${item.name} again in ${left} ${left === 1 ? 'second' : 'seconds'} (try ${attempt + 1} of ${this.maxAttempts}).`

            await new Promise((resolve) => setTimeout(resolve, 1000))
        }

        item.wait = 0
    },

    finish(item, result) {
        item.counts = result.counts

        switch (result.status) {
            case 'done':
                Object.assign(item, { state: 'done', note: '' })
                break

            case 'skipped':
                Object.assign(item, { state: 'skipped', note: result.message || 'Approved, so left as it is.' })
                break

            case 'disabled':
            case 'limited':
                // Not a failure: the suggestions from the numbers were made, and the AI's view was not available.
                Object.assign(item, { state: 'done', ruleOnly: true, note: result.message || "The AI's view is not available." })
                break

            default:
                Object.assign(item, {
                    state: 'failed',
                    ruleOnly: true,
                    note: `${result.message || 'The AI could not answer.'} ${item.name} keeps the suggestions from the numbers.`,
                })
        }
    },

    async ask(item) {
        const controller = new AbortController()
        const timer = setTimeout(() => controller.abort(), CLIENT_TIMEOUT_MS)

        let response

        try {
            response = await fetch(item.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.token,
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
        } catch (error) {
            throw new Error(
                error.name === 'AbortError'
                    ? 'The server took too long to answer. Try this student again.'
                    : 'Could not reach the server. Check your connection and try again.',
            )
        } finally {
            clearTimeout(timer)
        }

        if (response.status === 419) {
            throw new Error('Your session has expired. Reload the page, sign in again if asked, and try again.')
        }

        if (response.status === 403 || response.status === 404) {
            throw new Error('You cannot generate suggestions for this student.')
        }

        if (!response.ok) {
            throw new Error(`The server could not finish this student (error ${response.status}). Try again.`)
        }

        return response.json()
    },
})
