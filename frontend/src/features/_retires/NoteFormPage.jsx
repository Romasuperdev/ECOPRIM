import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, User, BookOpen, CalendarDays, Star, Hash, ListChecks, FileText } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import { fetchNote, createNote, updateNote } from './notesApi'
import { fetchAllEleves, fetchMatieres, fetchPeriodes } from '../reference/referenceApi'

const schema = z.object({
  eleve_id: z.string().min(1, "L'élève est requis"),
  matiere_id: z.string().min(1, 'La matière est requise'),
  periode_id: z.string().optional(),
  valeur: z.string().min(1, 'La note est requise'),
  coefficient: z.string().optional(),
  type_evaluation: z.string().optional(),
  date_evaluation: z.string().min(1, 'La date est requise'),
  appreciation: z.string().optional(),
})

export default function NoteFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: eleves } = useQuery({ queryKey: ['eleves', 'all'], queryFn: fetchAllEleves })
  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })
  const { data: periodes } = useQuery({ queryKey: ['periodes'], queryFn: () => fetchPeriodes() })

  const { data: note } = useQuery({
    queryKey: ['notes', id],
    queryFn: () => fetchNote(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema), defaultValues: { type_evaluation: 'devoir' } })

  useEffect(() => {
    if (note) {
      reset({
        eleve_id: String(note.eleve_id),
        matiere_id: String(note.matiere_id),
        periode_id: note.periode_id ? String(note.periode_id) : '',
        valeur: String(note.valeur),
        coefficient: note.coefficient ? String(note.coefficient) : '',
        type_evaluation: note.type_evaluation,
        date_evaluation: note.date_evaluation,
        appreciation: note.appreciation ?? '',
      })
    }
  }, [note, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = {
        ...values,
        periode_id: values.periode_id || null,
        coefficient: values.coefficient ? Number(values.coefficient) : null,
        valeur: Number(values.valeur),
      }
      return isEdit ? updateNote(id, payload) : createNote(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notes'] })
      navigate('/notes')
    },
  })

  return (
    <div className="max-w-xl">
      <Link to="/notes" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? 'Modifier la note' : 'Ajouter une note'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={User} title="Élève et matière">
          <Select icon={User} label="Élève" error={errors.eleve_id?.message} {...register('eleve_id')}>
            <option value="">— Sélectionner —</option>
            {eleves?.map((eleve) => (
              <option key={eleve.id} value={eleve.id}>
                {eleve.prenom} {eleve.nom} ({eleve.matricule})
              </option>
            ))}
          </Select>

          <div className="grid grid-cols-2 gap-4">
            <Select icon={BookOpen} label="Matière" error={errors.matiere_id?.message} {...register('matiere_id')}>
              <option value="">— Sélectionner —</option>
              {matieres?.map((matiere) => (
                <option key={matiere.id} value={matiere.id}>
                  {matiere.libelle}
                </option>
              ))}
            </Select>
            <Select icon={ListChecks} label="Période" error={errors.periode_id?.message} {...register('periode_id')}>
              <option value="">— Aucune —</option>
              {periodes?.map((periode) => (
                <option key={periode.id} value={periode.id}>
                  {periode.libelle}
                </option>
              ))}
            </Select>
          </div>
        </FormSection>

        <FormSection icon={Star} title="Évaluation">
          <div className="grid grid-cols-2 gap-4">
            <Input
              type="number"
              step="0.25"
              min="0"
              max="20"
              icon={Star}
              label="Note (/20)"
              error={errors.valeur?.message}
              {...register('valeur')}
            />
            <Input
              type="number"
              min="1"
              icon={Hash}
              label="Coefficient"
              error={errors.coefficient?.message}
              {...register('coefficient')}
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Select icon={ListChecks} label="Type d'évaluation" {...register('type_evaluation')}>
              <option value="devoir">Devoir</option>
              <option value="composition">Composition</option>
              <option value="interrogation">Interrogation</option>
            </Select>
            <Input
              type="date"
              icon={CalendarDays}
              label="Date"
              error={errors.date_evaluation?.message}
              {...register('date_evaluation')}
            />
          </div>

          <Input icon={FileText} label="Appréciation" error={errors.appreciation?.message} {...register('appreciation')} />
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
