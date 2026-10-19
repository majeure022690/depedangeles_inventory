export interface CropPixels {
  x: number
  y: number
  width: number
  height: number
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.addEventListener('load', () => resolve(img))
    img.addEventListener('error', reject)
    img.setAttribute('crossOrigin', 'anonymous')
    img.src = src
  })
}

/**
 * Renders the cropped region of `imageSrc` onto a canvas and exports it as
 * a File. react-easy-crop's `croppedAreaPixels` are in the *original*
 * image's pixel coordinates, so this needs no further scaling.
 *
 * `opacity` (0-1) is baked into the exported pixels rather than left as a
 * CSS effect, since the saved file is what actually gets served as the
 * banner. Below full opacity, `backgroundColor` is filled first so the
 * faded image blends with that color instead of the transparent canvas
 * showing through as black once flattened to a non-alpha format (JPEG).
 */
export async function getCroppedImageFile(
  imageSrc: string,
  cropPixels: CropPixels,
  fileName: string,
  mimeType: string,
  opacity = 1,
  backgroundColor = '#ffffff',
): Promise<File> {
  const image = await loadImage(imageSrc)

  const canvas = document.createElement('canvas')
  canvas.width = cropPixels.width
  canvas.height = cropPixels.height

  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Canvas 2D context is not available.')

  if (opacity < 1) {
    ctx.fillStyle = backgroundColor
    ctx.fillRect(0, 0, canvas.width, canvas.height)
  }

  ctx.globalAlpha = opacity
  ctx.drawImage(
    image,
    cropPixels.x,
    cropPixels.y,
    cropPixels.width,
    cropPixels.height,
    0,
    0,
    cropPixels.width,
    cropPixels.height,
  )
  ctx.globalAlpha = 1

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, mimeType, 0.92))
  if (!blob) throw new Error('Failed to export the cropped image.')

  return new File([blob], fileName, { type: mimeType })
}
