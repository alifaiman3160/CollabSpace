/*
 * "Download PDF" on the Documents list. The control is an ordinary link to the PDF route, so without JavaScript
 * it simply downloads. With it, the file is fetched so the button can show that it is being prepared, and a
 * failure becomes a short message instead of a blank page.
 */
export default () => ({
    loading: false,

    async start(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
        if (this.loading) return event.preventDefault()

        const link = event.currentTarget
        event.preventDefault()
        this.loading = true

        try {
            const response = await fetch(link.href, { headers: { 'Accept': '*/*', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })

            if (!response.ok) {
                const data = await response.json().catch(() => ({}))

                return this.fail(data.message)
            }

            const blob = await response.blob()
            const anchor = document.createElement('a')

            anchor.href = URL.createObjectURL(blob)
            anchor.download = this.filename(response) || 'document.pdf'
            document.body.appendChild(anchor)
            anchor.click()
            anchor.remove()
            setTimeout(() => URL.revokeObjectURL(anchor.href), 10000)
        } catch (e) {
            this.fail()
        } finally {
            this.loading = false
        }
    },

    filename(response) {
        const header = response.headers.get('Content-Disposition') || ''
        const star = header.match(/filename\*=UTF-8''([^;]+)/i)

        if (star) {
            try { return decodeURIComponent(star[1]) } catch (e) { /* fall through */ }
        }

        const plain = header.match(/filename="?([^";]+)"?/i)

        return plain ? plain[1] : ''
    },

    fail(message) {
        window.dispatchEvent(new CustomEvent('toast', {
            detail: { message: message || 'The PDF could not be prepared just now. Please try again in a moment.', tone: 'danger' },
        }))
    },
})