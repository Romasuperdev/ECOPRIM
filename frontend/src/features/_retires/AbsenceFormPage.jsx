import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, User, CalendarDays, ListChecks, BookOpen, FileText } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import { fetchAbsence, createAbsence, updateAbsence } from './absencesApi'
import { fetchAllEleves, fetchMatieres } from '../reference/referenceApi'

const schema = z.object({
  eleve_id: z.string().min(1, "L'élève est requis"),
  date_absence: z.string().min(1, 'La date est requise'),
  periode_jour: z.string().optional(),
  matiere_id: z.string().optional(),
  motif: z.string().optional(),
  justifiee: z.boolean().optional(),
})

export default function AbsenceFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: eleves } = useQuery({ queryKey: ['eleves', 'all'], queryFn: fetchAllEleves })
  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })

  const { data: absence } = useQuery({
    queryKey: ['absences', id],
    queryFn: () => fetchAbsence(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema), defaultValues: { periode_jour: 'journee' } })

  useEffect(() => {
    if (absence) {
      reset({
        eleve_id: String(absence.eleve_id),
        date_absence: absence.date_absence,
        periode_jour: absence.periode_jour ?? 'journee',
        matiere_id: absence.matiere_id ? String(absence.matiere_id) : '',
        motif: absence.motif ?? '',
        justifiee: absence.justifiee,
      })
    }
  }, [absence, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = { ...values, matiere_id: values.matiere_id || null }
      return isEdit ? updateAbsence(id, payload) : createAbsence(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['absences'] })
      navigate('/absences')
    },
  })

  return (
    <div className="max-w-xl">
      <Link to="/absences" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? "Modifier l'absence" : 'Signaler une absence'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={User} title="Élève">
          <Select icon={User} label="Élève" error={errors.eleve_id?.message} {...register('eleve_id')}>
            <option value="">— Sélectionner —</option>
            {eleves?.map((eleve) => (
              <option key={eleve.id} value={eleve.id}>
                {eleve.prenom} {eleve.nom} ({eleve.matricule})
              </option>
            ))}
          </Select>
        </FormSection>

        <FormSection icon={CalendarDays} title="Détails de l'absence">
          <div className="grid grid-cols-2 gap-4">
            <Input type="date" icon={CalendarDays} label="Date" error={errors.date_absence?.message} {...register('date_absence')} />
            <Select icon={ListChecks} label="Période" error={errors.periode_jour?.message} {...register('periode_jour')}>
              <option value="journee">Journée entière</option>
              <option value="matin">Matin</option>
              <option value="apres_midi">Après-midi</option>
            </Select>
          </div>

          <Select icon={BookOpen} label="Matière (optionnel)" error={errors.matiere_id?.message} {...register('matiere_id')}>
            <option value="">—</option>
            {matieres?.map((matiere) => (
              <option key={matiere.id} value={matiere.id}>
                {matiere.libelle}
              </option>
            ))}
          </Select>

          <Input icon={FileText} label="Motif" error={errors.motif?.message} {...register('motif')} />

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="rounded border-slate-300" {...register('justifiee')} />
            Absence justifiée
          </label>
        </FormSection>

        {mutation.isError && (
          <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">
            Une erreur est survenue. Vérifiez les champs et réessayez.
          </p>
        )}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="submit" disabled={isSubmitting || mutation.isPending}>
            {isEdit ? 'Enregistrer' : 'Créer'}
          </Button>
        </div>
      </form>
    </div>
  )
}
