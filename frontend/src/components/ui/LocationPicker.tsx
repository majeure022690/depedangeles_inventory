import { useEffect, useRef, useState } from 'react'
import L from 'leaflet'
import { MapContainer, TileLayer, Marker, useMapEvents, useMap, GeoJSON } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'

// Fix for default marker icons in Leaflet with Vite
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'

// @ts-expect-error Leaflet types are incomplete
delete L.Icon.Default.prototype._getIconUrl
L.Icon.Default.mergeOptions({
  iconUrl: markerIcon,
  iconRetinaUrl: markerIcon2x,
  shadowUrl: markerShadow,
})

interface GeoJSONFeature {
  type: string
  geometry: {
    type: string
    coordinates: number[][][]
  }
  properties: {
    adm3_en: string
    adm3_psgc: string
    [key: string]: unknown
  }
  id: number
}

interface GeoJSONData {
  type: string
  features: GeoJSONFeature[]
}

interface LocationPickerProps {
  latitude: number | null
  longitude: number | null
  onChange: (lat: number, lng: number) => void
  disabled?: boolean
  height?: string
  zoom?: number
}

/**
 * Interactive map for picking geographic coordinates. Click on the map to set a location,
 * or drag the marker. The map automatically centers on the current coordinates.
 * Shows Angeles City boundary polygon. Map is restricted to Angeles City area only.
 */
export function LocationPicker({ latitude, longitude, onChange, disabled = false, height = '400px', zoom = 13 }: LocationPickerProps) {
  // Default to Angeles City center if no coordinates provided
  const defaultLat = 15.1450
  const defaultLng = 120.5900
  
  const lat = latitude ?? defaultLat
  const lng = longitude ?? defaultLng
  const position: [number, number] = [lat, lng]
  
  const [boundaryData, setBoundaryData] = useState<GeoJSONData | null>(null)

  // Load Angeles City boundary on mount
  useEffect(() => {
    fetch('/angeles-city-boundary.json')
      .then(res => res.json())
      .then((data: GeoJSONData) => setBoundaryData(data))
      .catch(err => console.warn('Failed to load Angeles City boundary:', err))
  }, [])

  // Angeles City bounds - southwest and northeast corners
  const maxBounds: [[number, number], [number, number]] = [
    [15.108, 120.449], // Southwest corner
    [15.223, 120.642], // Northeast corner
  ]

  return (
    <div className="relative rounded-md border border-gray-300 overflow-hidden" style={{ height }}>
      <MapContainer
        center={position}
        zoom={zoom}
        minZoom={12}
        maxZoom={18}
        maxBounds={maxBounds}
        maxBoundsViscosity={1.0}
        style={{ height: '100%', width: '100%' }}
        scrollWheelZoom={!disabled}
        dragging={!disabled}
        zoomControl={!disabled}
      >
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        
        {/* Angeles City boundary */}
        {boundaryData && (
          <GeoJSON
            data={boundaryData as never}
            style={{
              fillColor: '#3b82f6',
              fillOpacity: 0.1,
              color: '#2563eb',
              weight: 2,
            }}
          />
        )}
        
        {/* Fit map to boundary on initial load */}
        <BoundaryFitter boundaryData={boundaryData} />
        
        {/* Update map center when coordinates change */}
        <MapUpdater position={position} />
        
        {/* Handle map clicks to set coordinates */}
        {!disabled && <MapClickHandler onChange={onChange} />}
        
        {/* Show marker if coordinates are set */}
        {latitude !== null && longitude !== null && (
          <DraggableMarker position={position} onChange={onChange} disabled={disabled} />
        )}
      </MapContainer>
      
      {disabled && (
        <div className="absolute inset-0 bg-gray-50/30 cursor-not-allowed" />
      )}
    </div>
  )
}

/** Component to handle map clicks and set coordinates */
function MapClickHandler({ onChange }: { onChange: (lat: number, lng: number) => void }) {
  useMapEvents({
    click: (e) => {
      onChange(e.latlng.lat, e.latlng.lng)
    },
  })
  return null
}

/** Component to update map view when coordinates change externally */
function MapUpdater({ position }: { position: [number, number] }) {
  const map = useMap()
  
  useEffect(() => {
    map.setView(position, map.getZoom())
  }, [position, map])
  
  return null
}

/** Component to fit map bounds to Angeles City boundary on initial load */
function BoundaryFitter({ boundaryData }: { boundaryData: GeoJSONData | null }) {
  const map = useMap()
  
  useEffect(() => {
    if (boundaryData && boundaryData.features.length > 0) {
      // Create a GeoJSON layer to calculate bounds
      const geoJsonLayer = L.geoJSON(boundaryData as never)
      const bounds = geoJsonLayer.getBounds()
      // Fit the map to the boundary with some padding
      map.fitBounds(bounds, { padding: [30, 30] })
    }
  }, [boundaryData, map])
  
  return null
}

/** Draggable marker component */
function DraggableMarker({ 
  position, 
  onChange, 
  disabled 
}: { 
  position: [number, number]
  onChange: (lat: number, lng: number) => void
  disabled: boolean
}) {
  const markerRef = useRef<L.Marker>(null)
  const [markerPosition, setMarkerPosition] = useState(position)

  // Update marker position when prop changes
  useEffect(() => {
    setMarkerPosition(position)
  }, [position])

  const eventHandlers = disabled
    ? {}
    : {
        dragend() {
          const marker = markerRef.current
          if (marker) {
            const latlng = marker.getLatLng()
            setMarkerPosition([latlng.lat, latlng.lng])
            onChange(latlng.lat, latlng.lng)
          }
        },
      }

  return (
    <Marker 
      position={markerPosition} 
      draggable={!disabled}
      eventHandlers={eventHandlers}
      ref={markerRef}
    />
  )
}
