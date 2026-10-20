import { Clock } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'

import { Button } from '@/components/ui/Button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { api } from '@/lib/api'

const LAST_ACTIVITY_KEY = 'dictis:last-activity'
const CHANNEL_NAME = 'dictis-session'
const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'click', 'keydown', 'wheel', 'scroll', 'touchstart', 'touchmove', 'pointerdown', 'pointermove'] as const
const WRITE_INTERVAL_MS = 1000
const IDLE_LOGIN_URL = '/login?reason=idle'
const LOGIN_URL = '/login'

function readSharedActivity(): number {
  try {
    return Number(localStorage.getItem(LAST_ACTIVITY_KEY)) || 0
  } catch {
    return 0
  }
}

function writeSharedActivity(time: number) {
  try {
    localStorage.setItem(LAST_ACTIVITY_KEY, String(time))
  } catch {
    return
  }
}

function warningWindowMs(timeoutMs: number): number {
  return Math.min(60_000, Math.floor(timeoutMs / 2))
}

export function IdleLogout() {
  const [timeoutMs, setTimeoutMs] = useState<number | null>(null)
  const [secondsLeft, setSecondsLeft] = useState<number | null>(null)
  const lastActivity = useRef(Date.now())
  const lastWrite = useRef(0)
  const signingOut = useRef(false)
  const warningOpen = useRef(false)
  const channel = useRef<BroadcastChannel | null>(null)

  useEffect(() => {
    api
      .get<{ data: { idle_timeout_seconds: number } }>('/session-settings')
      .then(({ data }) => setTimeoutMs(Math.max(10, data.data.idle_timeout_seconds) * 1000))
      .catch(() => setTimeoutMs(15 * 60 * 1000))
  }, [])

  const leave = useCallback((url: string = IDLE_LOGIN_URL) => {
    window.location.replace(url)
  }, [])

  const signOut = useCallback(
    async (url: string = IDLE_LOGIN_URL) => {
      if (signingOut.current) return
      signingOut.current = true
      channel.current?.postMessage({ type: 'logout', url })
      try {
        await api.post('/logout')
      } catch {
        return leave(url)
      }
      leave(url)
    },
    [leave],
  )

  const markActive = useCallback(() => {
    if (signingOut.current || warningOpen.current) return
    const now = Date.now()
    lastActivity.current = now
    if (now - lastWrite.current >= WRITE_INTERVAL_MS) {
      lastWrite.current = now
      writeSharedActivity(now)
    }
  }, [])

  const staySignedIn = useCallback(() => {
    if (signingOut.current) return
    const now = Date.now()
    lastActivity.current = now
    lastWrite.current = now
    writeSharedActivity(now)
    warningOpen.current = false
    setSecondsLeft(null)
  }, [])

  const check = useCallback(() => {
    if (timeoutMs === null || signingOut.current) return
    const latest = Math.max(lastActivity.current, readSharedActivity())
    lastActivity.current = latest
    const idle = Date.now() - latest

    if (idle >= timeoutMs) {
      void signOut()
      return
    }

    const remaining = timeoutMs - idle
    const warn = remaining <= warningWindowMs(timeoutMs)
    warningOpen.current = warn
    setSecondsLeft(warn ? Math.ceil(remaining / 1000) : null)
  }, [signOut, timeoutMs])

  useEffect(() => {
    if (timeoutMs === null) return

    const now = Date.now()
    lastActivity.current = now
    lastWrite.current = now
    writeSharedActivity(now)

    const options: AddEventListenerOptions = { passive: true, capture: true }
    ACTIVITY_EVENTS.forEach((name) => window.addEventListener(name, markActive, options))
    const onVisible = () => check()
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('focus', onVisible)

    let worker: Worker | null = null
    let interval: number | null = null
    try {
      worker = new Worker(new URL('../lib/idleTicker.worker.ts', import.meta.url), { type: 'module' })
      worker.onmessage = () => check()
    } catch {
      interval = window.setInterval(check, 1000)
    }

    if ('BroadcastChannel' in window) {
      channel.current = new BroadcastChannel(CHANNEL_NAME)
      channel.current.onmessage = (event) => {
        if (event.data?.type === 'logout' && !signingOut.current) {
          signingOut.current = true
          leave(event.data.url === LOGIN_URL ? LOGIN_URL : IDLE_LOGIN_URL)
        }
      }
    }

    return () => {
      ACTIVITY_EVENTS.forEach((name) => window.removeEventListener(name, markActive, options))
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('focus', onVisible)
      worker?.terminate()
      if (interval !== null) window.clearInterval(interval)
      channel.current?.close()
      channel.current = null
    }
  }, [check, leave, markActive, timeoutMs])

  return (
    <Dialog open={secondsLeft !== null} onOpenChange={(open) => !open && staySignedIn()}>
      <DialogContent role="alertdialog" className="max-w-md" onInteractOutside={(event) => event.preventDefault()}>
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-warning-50 text-warning-600">
            <Clock className="h-5 w-5" />
          </span>
          <div>
            <DialogTitle>Are you still there?</DialogTitle>
            <DialogDescription className="mt-1">
              For your security, you will be signed out in{' '}
              <strong className="text-gray-900">
                {secondsLeft ?? 0} second{secondsLeft === 1 ? '' : 's'}
              </strong>{' '}
              because there has been no activity.
            </DialogDescription>
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => void signOut(LOGIN_URL)}>
            Sign out now
          </Button>
          <Button autoFocus onClick={staySignedIn}>
            Stay signed in
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
