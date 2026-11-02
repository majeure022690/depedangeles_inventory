import { ArrowLeft, Download, Save } from 'lucide-react'
import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'

import { FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { PersonPicker } from '@/components/forms/PersonPicker'
import { Box1IrisView, Table1View, Table2View, Table3View } from '@/components/gsp-profiles/GspIrisTables'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import { gspProfileService } from '@/services/gspProfileService'
import {
  RADIO_GRADES,
  type GspIrisData,
  type GspProfile,
  type GspProfileFormData,
  type GspProfileOptions,
  type PersonOption,
  type SchoolLevel,
} from '@/types/gsp-profile'

const TABS_ID = 'gsp-profile'

type SignatoryKey = 'school_head_id' | 'ito_id' | 'planning_officer_id'

const SECTIONS = [
  { value: 'profile', label: 'Profile' },
  { value: 'table1', label: 'Table 1' },
  { value: 'table2', label: 'Table 2' },
  { value: 'table3', label: 'Table 3' },
  { value: 'table4', label: 'Table 4' },
  { value: 'box1', label: 'Box 1' },
  { value: 'signatories', label: 'Signatories' },
] as const

type Section = (typeof SECTIONS)[number]['value']

const FIELD_SECTION: Record<string, Section> = {
  office_id: 'profile',
  school_year: 'profile',
  as_of: 'profile',
  level: 'profile',
  radio_teachers: 'table4',
  dict_free_wifi: 'box1',
  dict_free_wifi_installed: 'box1',
  isp_reliability: 'box1',
  school_head_id: 'signatories',
  ito_id: 'signatories',
  planning_officer_id: 'signatories',
}

/**
 * Mirrors the workbook tab by tab. Tables 1–3 and the IRIS parts of Box 1 are
 * read-only, computed live from Equipment, ISP Accounts and the Internet
 * Connectivity survey; everything else is stored on the profile.
 */
export function GspProfileForm({
  initial,
  options,
  officeLocked,
  profileId,
  readOnly = false,
  canDownload = false,
  currentPeople,
  submit,
  onSaved,
}: {
  initial: GspProfileFormData
  options: GspProfileOptions
  officeLocked: boolean
  profileId?: number
  readOnly?: boolean
  canDownload?: boolean
  /** The saved signatories, so a person no longer in a picker list still shows. */
  currentPeople?: Pick<GspProfile, 'school_head' | 'ito' | 'planning_officer'>
  submit: (form: GspProfileFormData) => Promise<{ data: GspProfile; message: string }>
  onSaved: (profile: GspProfile, message: string) => void
}) {
  const navigate = useNavigate()
  const [form, setForm] = useState(initial)
  const [section, setSection] = useState<Section>('profile')
  const [accountList, setAccountList] = useState<{
    officeId: string
    accounts: NonNullable<GspProfileOptions['isp_accounts']>
    schoolHeads: PersonOption[]
  } | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [downloading, setDownloading] = useState(false)

  useEffect(() => {
    if (form.office_id === '') return
    let cancelled = false
    const officeId = form.office_id
    gspProfileService
      .options(Number(officeId))
      .then((loaded) => {
        if (!cancelled) setAccountList({ officeId, accounts: loaded.isp_accounts ?? [], schoolHeads: loaded.school_heads ?? [] })
      })
      .catch(() => {
        if (!cancelled) setAccountList({ officeId, accounts: [], schoolHeads: [] })
      })
    return () => {
      cancelled = true
    }
  }, [form.office_id])

  const accounts = accountList && accountList.officeId === form.office_id ? accountList.accounts : []
  const schoolHeads = accountList && accountList.officeId === form.office_id ? accountList.schoolHeads : []

  const [clearedSignatories, setClearedSignatories] = useState<SignatoryKey[]>([])
  const onlyChoice = (key: SignatoryKey, people: PersonOption[]) =>
    form[key] === '' && people.length === 1 && !clearedSignatories.includes(key) ? String(people[0].value) : form[key]
  const signatories: Pick<typeof form, SignatoryKey> = {
    school_head_id: onlyChoice('school_head_id', schoolHeads),
    ito_id: onlyChoice('ito_id', options.itos),
    planning_officer_id: onlyChoice('planning_officer_id', options.planning_officers),
  }

  function setSignatory(key: SignatoryKey, value: string) {
    setClearedSignatories((prev) => (value === '' ? [...prev, key] : prev.filter((cleared) => cleared !== key)))
    set(key, value)
  }

  const previewKey = /^\d{4}-\d{4}$/.test(form.school_year) && form.office_id !== '' ? `${form.office_id}|${form.school_year}|${form.level}` : null
  const [preview, setPreview] = useState<{ key: string; data: GspIrisData | null; error: string | null } | null>(null)

  useEffect(() => {
    if (previewKey === null) return
    let cancelled = false
    const [officeId, schoolYear, level] = previewKey.split('|')
    gspProfileService
      .preview({ office_id: Number(officeId), school_year: schoolYear, level })
      .then((data) => {
        if (!cancelled) setPreview({ key: previewKey, data, error: null })
      })
      .catch((error: unknown) => {
        if (!cancelled) setPreview({ key: previewKey, data: null, error: extractError(error, 'Unable to load the tables from IRIS.') })
      })
    return () => {
      cancelled = true
    }
  }, [previewKey])

  const iris = preview && preview.key === previewKey ? preview : null

  function irisPanel(render: (data: GspIrisData) => ReactNode) {
    if (previewKey === null)
      return (
        <p className="rounded-md bg-gray-50 px-3 py-6 text-center text-sm text-gray-500">
          Choose the office and a school year on the Profile tab to see this table.
        </p>
      )
    if (iris === null) return <Skeleton className="h-64 w-full" />
    if (iris.error || !iris.data)
      return (
        <Alert type="error">
          <span role="alert">{iris.error}</span>
        </Alert>
      )
    return render(iris.data)
  }

  function set<K extends keyof GspProfileFormData>(field: K, value: GspProfileFormData[K]) {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  function changeOffice(officeId: string) {
    const office = options.office.find((option) => String(option.value) === officeId)
    setForm((prev) => ({
      ...prev,
      office_id: officeId,
      level: office?.default_level ?? prev.level,
      isp_reliability: {},
      school_head_id: '',
    }))
    setClearedSignatories((prev) => prev.filter((cleared) => cleared !== 'school_head_id'))
  }

  function changeSchoolYear(value: string) {
    const end = /^\d{4}-(\d{4})$/.exec(value)?.[1]
    setForm((prev) => ({
      ...prev,
      school_year: value,
      as_of: end ? `${end}-03-31` : prev.as_of,
    }))
  }

  function setRadio(grade: (typeof RADIO_GRADES)[number], field: 'literacy' | 'numeracy', value: string) {
    setForm((prev) => ({
      ...prev,
      radio_teachers: {
        ...prev.radio_teachers,
        [grade]: { ...prev.radio_teachers[grade], [field]: value },
      },
    }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (readOnly) return
    setSaving(true)
    setErrors({})
    setErrorMessage(null)
    try {
      const response = await submit({ ...form, ...signatories })
      onSaved(response.data, response.message)
    } catch (error) {
      if (isValidationError(error)) {
        const fieldErrors = extractFieldErrors(error)
        setErrors(fieldErrors)
        const first = Object.keys(fieldErrors)
          .map((field) => FIELD_SECTION[field])
          .find(Boolean)
        if (first) setSection(first)
        setErrorMessage('Please fix the highlighted fields.')
      } else {
        setErrorMessage(extractError(error, 'Unable to save the GSP ICT profile. Please try again.'))
      }
    } finally {
      setSaving(false)
    }
  }

  async function download() {
    if (profileId === undefined) return
    setDownloading(true)
    setErrorMessage(null)
    try {
      await gspProfileService.download(profileId)
    } catch (error) {
      setErrorMessage(extractError(error, 'Unable to generate the workbook.'))
    } finally {
      setDownloading(false)
    }
  }

  const tabs = SECTIONS.map((item) => ({
    value: item.value,
    label: item.label,
    errorCount: Object.keys(errors).filter((field) => FIELD_SECTION[field] === item.value).length || undefined,
  }))

  const literacyTotal = RADIO_GRADES.reduce((sum, grade) => sum + (Number(form.radio_teachers[grade].literacy) || 0), 0)
  const numeracyTotal = RADIO_GRADES.reduce((sum, grade) => sum + (Number(form.radio_teachers[grade].numeracy) || 0), 0)

  return (
    <form onSubmit={handleSubmit} noValidate className="space-y-4">
      {errorMessage && (
        <Alert type="error">
          <span role="alert">{errorMessage}</span>
        </Alert>
      )}

      <Tabs id={TABS_ID} label="GSP ICT profile sections" tabs={tabs} value={section} onChange={(value) => setSection(value as Section)} />

      <fieldset disabled={readOnly || saving}>
        <TabPanel tabsId={TABS_ID} value="profile" active={section === 'profile'}>
          <FormSection title="School and period" columns={4}>
            <FormField
              id="office_id"
              label="Office"
              required
              error={errors.office_id}
              note={officeLocked ? 'Profiles are recorded under your own office.' : undefined}
            >
              {(aria) => (
                <Select
                  id="office_id"
                  value={form.office_id}
                  placeholder="Select an office"
                  options={options.office.map((office) => ({
                    value: String(office.value),
                    label: office.label,
                  }))}
                  disabled={officeLocked || profileId !== undefined}
                  onChange={(event) => changeOffice(event.target.value)}
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="school_year" label="School year" required error={errors.school_year}>
              {(aria) => (
                <Input
                  id="school_year"
                  value={form.school_year}
                  placeholder="2025-2026"
                  maxLength={9}
                  onChange={(event) => changeSchoolYear(event.target.value)}
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="as_of" label="As of" required error={errors.as_of} hint="The count date printed under every table title.">
              {(aria) => <Input id="as_of" type="date" value={form.as_of} onChange={(event) => set('as_of', event.target.value)} {...aria} />}
            </FormField>
            <FormField id="level" label="School level" required error={errors.level} hint="Table 1 level for learner and teacher computers whose equipment School level is Not applicable or not yet set.">
              {(aria) => (
                <Select id="level" value={form.level} options={options.level} onChange={(event) => set('level', event.target.value as SchoolLevel)} {...aria} />
              )}
            </FormField>
          </FormSection>
          <p className="mt-3 text-sm text-gray-600">
            Tables 1–3 and the survey and subscription parts of Box 1 are calculated live from Equipment, ISP Accounts and the Internet Connectivity survey;
            open their tabs to check them. Table 4, the DICT Free WiFi answer, service reliability and the signatories are entered here.
          </p>
        </TabPanel>

        <TabPanel tabsId={TABS_ID} value="table1" active={section === 'table1'}>
          {irisPanel((data) => (
            <Table1View data={data.table_1} level={form.level} />
          ))}
        </TabPanel>

        <TabPanel tabsId={TABS_ID} value="table2" active={section === 'table2'}>
          {irisPanel((data) => (
            <Table2View rows={data.table_2} />
          ))}
        </TabPanel>

        <TabPanel tabsId={TABS_ID} value="table3" active={section === 'table3'}>
          {irisPanel((data) => (
            <Table3View data={data.table_3} />
          ))}
        </TabPanel>

        <TabPanel tabsId={TABS_ID} value="table4" active={section === 'table4'}>
          <FormSection title="Table 4. Number of teachers using radio for instructional use" columns={2}>
            <div className="sm:col-span-2">
              {errors.radio_teachers && <p className="mb-2 text-xs text-error-600">{errors.radio_teachers}</p>}
              <table className="w-full max-w-3xl text-sm">
                <thead>
                  <tr className="border-b border-gray-200 text-left text-gray-600">
                    <th scope="col" className="py-2 pr-4 font-semibold">
                      Grade level
                    </th>
                    <th scope="col" className="py-2 pr-4 font-semibold">
                      Literacy
                    </th>
                    <th scope="col" className="py-2 pr-4 font-semibold">
                      Numeracy
                    </th>
                    <th scope="col" className="py-2 font-semibold">
                      Subtotal
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {RADIO_GRADES.map((grade, index) => (
                    <tr key={grade} className="border-b border-gray-100">
                      <th scope="row" className="py-2 pr-4 text-left font-medium text-gray-800">
                        Grade {index + 1}
                      </th>
                      {(['literacy', 'numeracy'] as const).map((field) => (
                        <td key={field} className="py-2 pr-4">
                          <Input
                            type="number"
                            min={0}
                            aria-label={`Grade ${index + 1} ${field} teachers`}
                            value={form.radio_teachers[grade][field]}
                            onChange={(event) => setRadio(grade, field, event.target.value)}
                          />
                        </td>
                      ))}
                      <td className="py-2 tabular-nums text-gray-700">
                        {(Number(form.radio_teachers[grade].literacy) || 0) + (Number(form.radio_teachers[grade].numeracy) || 0)}
                      </td>
                    </tr>
                  ))}
                  <tr className="font-semibold">
                    <th scope="row" className="py-2 pr-4 text-left">
                      Total
                    </th>
                    <td className="py-2 pr-4 tabular-nums">{literacyTotal}</td>
                    <td className="py-2 pr-4 tabular-nums">{numeracyTotal}</td>
                    <td className="py-2 tabular-nums">{literacyTotal + numeracyTotal}</td>
                  </tr>
                </tbody>
              </table>
              <p className="mt-2 text-xs text-gray-500">Count each teacher once, under the main grade level of their approved class program.</p>
            </div>
          </FormSection>
        </TabPanel>

        <TabPanel tabsId={TABS_ID} value="box1" active={section === 'box1'}>
          <div className="space-y-4">
            {irisPanel((data) => (
              <Box1IrisView data={data.box_1} reliability={form.isp_reliability} />
            ))}
            <FormSection title="Q4. DICT Free WiFi" columns={4}>
              <FormField id="dict_free_wifi" label="Recipient of DICT Free WiFi? (optional)" error={errors.dict_free_wifi}>
                {(aria) => (
                  <Select
                    id="dict_free_wifi"
                    value={form.dict_free_wifi}
                    placeholder="Not answered"
                    options={options.dict_free_wifi}
                    onChange={(event) => set('dict_free_wifi', event.target.value)}
                    {...aria}
                  />
                )}
              </FormField>
              <FormField
                id="dict_free_wifi_installed"
                label="Date of installation / activation (optional)"
                error={errors.dict_free_wifi_installed}
                hint="Month and year, e.g. October 2023."
              >
                {(aria) => (
                  <Input
                    id="dict_free_wifi_installed"
                    value={form.dict_free_wifi_installed}
                    maxLength={30}
                    placeholder="October 2023"
                    disabled={form.dict_free_wifi === '' || form.dict_free_wifi === 'not_recipient'}
                    onChange={(event) => set('dict_free_wifi_installed', event.target.value)}
                    {...aria}
                  />
                )}
              </FormField>
            </FormSection>
            <FormSection title="Q2. Service reliability (entered here)" columns={4}>
              {errors.isp_reliability && <p className="text-xs text-error-600 sm:col-span-2 lg:col-span-3 2xl:col-span-4">{errors.isp_reliability}</p>}
              {accounts.length === 0 ? (
                <p className="text-sm text-gray-500 sm:col-span-2 lg:col-span-3 2xl:col-span-4">
                  {form.office_id === '' ? 'Choose an office first.' : 'This office has no active ISP accounts. Add them under ISP Accounts.'}
                </p>
              ) : (
                accounts.map((account) => (
                  <FormField
                    key={account.value}
                    id={`reliability-${account.value}`}
                    label={account.label}
                    hint="Interruptions during school hours in a 5-day school week (see Box 1 definition 10)."
                  >
                    {(aria) => (
                      <Select
                        id={`reliability-${account.value}`}
                        value={form.isp_reliability[String(account.value)] ?? ''}
                        placeholder="Not rated"
                        options={options.reliability}
                        onChange={(event) =>
                          set('isp_reliability', {
                            ...form.isp_reliability,
                            [String(account.value)]: event.target.value,
                          })
                        }
                        {...aria}
                      />
                    )}
                  </FormField>
                ))
              )}
            </FormSection>
          </div>
        </TabPanel>

        <TabPanel tabsId={TABS_ID} value="signatories" active={section === 'signatories'}>
          <FormSection title="Cover page signatories" columns={3}>
            <PersonPicker
              id="school_head_id"
              label="School head (optional)"
              hint="Principals, head teachers and OICs of this school. Picked automatically when there is only one. The position on the cover comes from their Personnel record."
              people={schoolHeads}
              current={currentPeople?.school_head ?? null}
              value={signatories.school_head_id}
              onChange={(value) => setSignatory('school_head_id', value)}
              placeholder={form.office_id === '' ? 'Choose the office first' : 'Select the school head'}
              disabled={form.office_id === ''}
              error={errors.school_head_id}
            />
            <PersonPicker
              id="ito_id"
              label="Information Technology Officer (optional)"
              hint="Division personnel whose position is Information Technology Officer."
              people={options.itos}
              current={currentPeople?.ito ?? null}
              value={signatories.ito_id}
              onChange={(value) => setSignatory('ito_id', value)}
              placeholder="Select the ITO"
              error={errors.ito_id}
            />
            <PersonPicker
              id="planning_officer_id"
              label="Planning Officer III (optional)"
              hint="Division personnel whose position is Planning Officer."
              people={options.planning_officers}
              current={currentPeople?.planning_officer ?? null}
              value={signatories.planning_officer_id}
              onChange={(value) => setSignatory('planning_officer_id', value)}
              placeholder="Select the Planning Officer"
              error={errors.planning_officer_id}
            />
          </FormSection>
        </TabPanel>
      </fieldset>

      <div className="flex flex-wrap justify-between gap-2">
        <Button type="button" variant="outline" leftIcon={<ArrowLeft className="h-4 w-4" />} onClick={() => navigate('/gsp-profiles')}>
          Back to list
        </Button>
        <div className="flex gap-2">
          {canDownload && profileId !== undefined && (
            <Button type="button" variant="outline" leftIcon={<Download className="h-4 w-4" />} isLoading={downloading} onClick={download}>
              Download GSP
            </Button>
          )}
          {!readOnly && (
            <Button type="submit" leftIcon={<Save className="h-4 w-4" />} isLoading={saving}>
              Save
            </Button>
          )}
        </div>
      </div>
    </form>
  )
}
