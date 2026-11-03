import QrScanner from 'qr-scanner'
import { useEffect, useRef, useState } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/Dialog'

export function useHasCamera(): boolean {
  const [hasCamera, setHasCamera] = useState(false)

  useEffect(() => {
    let cancelled = false
    QrScanner.hasCamera()
      .then((available) => !cancelled && setHasCamera(available))
      .catch(() => !cancelled && setHasCamera(false))
    return () => {
      cancelled = true
    }
  }, [])

  return hasCamera
}

/** Reads one QR code with the device camera, preferring the rear camera. */
export function QrScanDialog({ open, onOpenChange, onScan }: { open: boolean; onOpenChange: (open: boolean) => void; onScan: (code: string) => void }) {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const [error, setError] = useState<string | null>(null)
  const onScanRef = useRef(onScan)
  onScanRef.current = onScan

  useEffect(() => {
    if (!open) return
    setError(null)
    let scanner: QrScanner | null = null
    let done = false

    const frame = requestAnimationFrame(() => {
      if (!videoRef.current) return
      scanner = new QrScanner(
        videoRef.current,
        (result) => {
          if (done) return
          done = true
          scanner?.stop()
          onScanRef.current(result.data)
        },
        { returnDetailedScanResult: true, preferredCamera: 'environment', highlightScanRegion: true, highlightCodeOutline: true },
      )
      scanner.start().catch(() => setError('The camera could not be started. Allow camera access for this site, or type the property number instead.'))
    })

    return () => {
      cancelAnimationFrame(frame)
      scanner?.destroy()
    }
  }, [open])

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="md">
        <DialogHeader>
          <DialogTitle>Scan QR label</DialogTitle>
          <DialogDescription>Point the camera at the item's IRIS QR label.</DialogDescription>
        </DialogHeader>
        {error ? (
          <Alert type="error">
            <span role="alert">{error}</span>
          </Alert>
        ) : (
          <div className="overflow-hidden rounded-md bg-black">
            <video ref={videoRef} className="aspect-square w-full object-cover" muted playsInline />
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
