import { useEffect, useRef, useState } from 'react'
import Cropper, { type Area } from 'react-easy-crop'
// Without this, Tailwind's Preflight (img { max-width: 100%; height: auto })
// fights the library's own inline transform-based scaling — the image
// renders "contained" and tiny inside the crop frame instead of covering it.
import 'react-easy-crop/react-easy-crop.css'

import { Button } from '@/components/ui/Button'
import { Dialog, DialogContent, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { cn } from '@/lib/utils'
import { getCroppedImageFile, type CropPixels } from '@/lib/cropImage'

interface ImageCropDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  imageSrc: string
  fileName: string
  mimeType: string
  /** Starting width/height ratio for the crop frame (defaults to square). Corner handles can freely resize away from it. */
  aspect?: number
  initialOpacity?: number
  /** Hides the opacity slider (e.g. for avatars, where a faded photo makes no sense). */
  showOpacity?: boolean
  onConfirm: (file: File, opacity: number) => void
}

const MIN_ZOOM = 1
const MAX_ZOOM = 3
const MIN_OPACITY = 0.1
const MAX_OPACITY = 1
const POSITION_LIMIT = 1000
// Floor so a corner can't be dragged down to a degenerate/zero-size frame.
const MIN_FRAME_SIZE = 60

type Corner = 'tl' | 'tr' | 'bl' | 'br'

// react-easy-crop always centers its crop area within the container (see
// react-easy-crop.css's .reactEasyCrop_CropArea — left/top 50% + translate
// -50%/-50%), so the crop area can only be *resized*, never repositioned
// independently within the container. That makes "grow/shrink symmetrically
// from the center" the only resize model the library actually supports —
// dragging a corner moves that corner out/in and the opposite edges move
// the same amount in the other direction to stay centered.
const CORNER_SIGN: Record<Corner, { x: -1 | 1; y: -1 | 1 }> = {
  tl: { x: -1, y: -1 },
  tr: { x: 1, y: -1 },
  bl: { x: -1, y: 1 },
  br: { x: 1, y: 1 },
}

const CORNER_CURSOR: Record<Corner, string> = {
  tl: 'cursor-nwse-resize',
  tr: 'cursor-nesw-resize',
  bl: 'cursor-nesw-resize',
  br: 'cursor-nwse-resize',
}

interface Size {
  width: number
  height: number
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

export function ImageCropDialog({
  open,
  onOpenChange,
  imageSrc,
  fileName,
  mimeType,
  aspect: initialAspect = 1,
  initialOpacity = 1,
  showOpacity = true,
  onConfirm,
}: ImageCropDialogProps) {
  const [crop, setCrop] = useState({ x: 0, y: 0 })
  const [zoom, setZoom] = useState(1)
  const [opacity, setOpacity] = useState(initialOpacity)
  const [croppedAreaPixels, setCroppedAreaPixels] = useState<Area | null>(null)
  const [processing, setProcessing] = useState(false)

  useEffect(() => {
    if (open) setOpacity(initialOpacity)
  }, [open, imageSrc, initialOpacity])

  const containerRef = useRef<HTMLDivElement>(null)
  const [containerSize, setContainerSize] = useState<Size | null>(null)
  // The crop frame's own width/height, independently adjustable via the
  // corner handles — distinct from `zoom`, which scales the *photo* inside
  // whatever frame size is currently set, not the frame itself.
  const [frameSize, setFrameSize] = useState<Size | null>(null)
  const dragState = useRef<{ corner: Corner; startX: number; startY: number; startWidth: number; startHeight: number } | null>(null)

  useEffect(() => {
    const container = containerRef.current
    if (!container) return

    const observer = new ResizeObserver(([entry]) => {
      const width = entry.contentRect.width
      const height = entry.contentRect.height
      if (width <= 0 || height <= 0) return

      setContainerSize({ width, height })
      // Only seed the frame's starting size once — after that it's under
      // the admin's own control via the corner handles, not re-derived
      // from the container on every resize/re-measure.
      setFrameSize((prev) => prev ?? { width, height: width / initialAspect })
    })
    observer.observe(container)
    return () => observer.disconnect()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function handleCropComplete(_croppedArea: Area, pixels: Area) {
    setCroppedAreaPixels(pixels)
  }

  function handleHandlePointerDown(e: React.PointerEvent<HTMLDivElement>, corner: Corner) {
    if (!frameSize) return
    e.preventDefault()
    e.stopPropagation()
    e.currentTarget.setPointerCapture(e.pointerId)
    dragState.current = { corner, startX: e.clientX, startY: e.clientY, startWidth: frameSize.width, startHeight: frameSize.height }
  }

  function handleHandlePointerMove(e: React.PointerEvent<HTMLDivElement>) {
    const drag = dragState.current
    if (!drag || !containerSize) return

    const sign = CORNER_SIGN[drag.corner]
    const dx = (e.clientX - drag.startX) * sign.x
    const dy = (e.clientY - drag.startY) * sign.y

    // Growing from the center means both edges move outward together, so
    // the frame's full width/height changes by 2x the corner's own travel.
    const nextWidth = clamp(drag.startWidth + dx * 2, MIN_FRAME_SIZE, containerSize.width)
    const nextHeight = clamp(drag.startHeight + dy * 2, MIN_FRAME_SIZE, containerSize.height)

    setFrameSize({ width: nextWidth, height: nextHeight })
  }

  function handleHandlePointerUp(e: React.PointerEvent<HTMLDivElement>) {
    dragState.current = null
    e.currentTarget.releasePointerCapture(e.pointerId)
  }

  async function handleConfirm() {
    if (!croppedAreaPixels) return
    setProcessing(true)
    try {
      await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))
      const file = await getCroppedImageFile(imageSrc, croppedAreaPixels as CropPixels, fileName, mimeType, opacity)
      onConfirm(file, opacity)
      onOpenChange(false)
    } finally {
      setProcessing(false)
    }
  }

  const frameAspect = frameSize ? frameSize.width / frameSize.height : initialAspect
  // The frame is always centered in the container (react-easy-crop's own
  // behavior) — handles track that centered rectangle, not the container's
  // own corners, which stop matching once the frame's been resized smaller.
  const frameOffset =
    containerSize && frameSize
      ? { left: (containerSize.width - frameSize.width) / 2, top: (containerSize.height - frameSize.height) / 2 }
      : { left: 0, top: 0 }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="lg">
        <DialogTitle>Crop Banner Image</DialogTitle>

        <div className="relative mt-3 w-full" style={{ aspectRatio: initialAspect }}>
          {/* Cropping viewport clips to its own box (overflow-hidden) — the
              corner handles live OUTSIDE this div (as its siblings, in the
              unclipped wrapper above) so they're never cut off at the edge,
              which happens by default since the frame starts at full
              container width/height and its handles sit exactly on that
              boundary. */}
          <div ref={containerRef} className="absolute inset-0 touch-none overflow-hidden rounded-lg bg-gray-100">
            <Cropper
              image={imageSrc}
              crop={crop}
              zoom={zoom}
              aspect={frameAspect}
              cropSize={frameSize ?? undefined}
              minZoom={MIN_ZOOM}
              maxZoom={MAX_ZOOM}
              onCropChange={setCrop}
              onZoomChange={setZoom}
              onCropComplete={handleCropComplete}
              style={{ mediaStyle: { opacity } }}
            />
          </div>

          {frameSize && (
            <div
              className="pointer-events-none absolute z-[5] border border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.12)]"
              style={{
                left: frameOffset.left,
                top: frameOffset.top,
                width: frameSize.width,
                height: frameSize.height,
              }}
            >
              <div className="absolute bottom-0 left-1/3 top-0 border-l border-white/60" />
              <div className="absolute bottom-0 left-2/3 top-0 border-l border-white/60" />
              <div className="absolute bottom-1/3 left-0 right-0 border-t border-white/60" />
              <div className="absolute bottom-2/3 left-0 right-0 border-t border-white/60" />
            </div>
          )}

          {frameSize &&
            (Object.keys(CORNER_SIGN) as Corner[]).map((corner) => {
              const isTop = corner === 'tl' || corner === 'tr'
              const isLeft = corner === 'tl' || corner === 'bl'
              return (
                <div
                  key={corner}
                  onPointerDown={(e) => handleHandlePointerDown(e, corner)}
                  onPointerMove={handleHandlePointerMove}
                  onPointerUp={handleHandlePointerUp}
                  className={cn(
                    'absolute z-10 h-5 w-5 touch-none rounded-sm border-2 border-primary-500 bg-white shadow',
                    CORNER_CURSOR[corner],
                  )}
                  style={{
                    left: (isLeft ? frameOffset.left : frameOffset.left + frameSize.width) - 10,
                    top: (isTop ? frameOffset.top : frameOffset.top + frameSize.height) - 10,
                  }}
                />
              )
            })}
        </div>

        <div className="mt-4 flex items-center gap-3">
          <span className="w-14 shrink-0 text-xs text-gray-500">Zoom</span>
          <input
            type="range"
            min={MIN_ZOOM}
            max={MAX_ZOOM}
            step={0.01}
            value={zoom}
            onChange={(e) => setZoom(Number(e.target.value))}
            className="w-full accent-primary-600"
          />
        </div>
        {showOpacity && (
        <div className="mt-2 flex items-center gap-3">
          <span className="w-14 shrink-0 text-xs text-gray-500">Opacity</span>
          <input
            type="range"
            min={MIN_OPACITY}
            max={MAX_OPACITY}
            step={0.01}
            value={opacity}
            onChange={(e) => setOpacity(Number(e.target.value))}
            className="w-full accent-primary-600"
          />
          <span className="w-10 shrink-0 text-right text-xs text-gray-500">{Math.round(opacity * 100)}%</span>
        </div>
        )}
        <div className="mt-2 flex items-center gap-3">
          <span className="w-14 shrink-0 text-xs text-gray-500">Vertical</span>
          <input
            type="range"
            min={-POSITION_LIMIT}
            max={POSITION_LIMIT}
            step={1}
            value={clamp(crop.y, -POSITION_LIMIT, POSITION_LIMIT)}
            onChange={(e) => setCrop((current) => ({ ...current, y: Number(e.target.value) }))}
            className="w-full accent-primary-600"
            aria-label="Move image vertically"
          />
          <Button variant="ghost" size="sm" onClick={() => setCrop((current) => ({ ...current, y: 0 }))}>
            Center
          </Button>
        </div>
        <p className="mt-2 text-xs text-gray-500">
          Drag inside the frame to reposition the photo, use Vertical for precise up/down placement, drag a corner handle to resize the frame, or use {showOpacity ? 'Zoom/Opacity' : 'Zoom'} to refine it.
        </p>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button isLoading={processing} onClick={handleConfirm}>
            Use This Crop
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
