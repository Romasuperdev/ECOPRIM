import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, User, CalendarDays, Clock, Hash, FileText } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import { fetchRetard, createRetard, updateRetard } from './retardsApi'
import { fetchAllEleves } from '../reference/referenceApi'

const schema = z.object({
  eleve_id: z.string().min(1, "L'élève est requis"),
  date_retard: z.string().min(1, 'La date est requise'),
  heure_arrivee: z.string().optional(),
  duree_minutes: z.string().optional(),
  motif: z.string().optional(),
  justifie: z.boolean().optional(),
})

export default function RetardFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: eleves } = useQuery({ queryKey: ['eleves', 'all'], queryFn: fetchAllEleves })

  const { data: retard } = useQuery({
    queryKey: ['retards', id],
    queryFn: () => fetchRetard(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (retard) {
      reset({
        eleve_id: String(retard.eleve_id),
        date_retard: retard.date_retard,
        heure_arrivee: retard.heure_arrivee?.slice(0, 5) ?? '',
        duree_minutes: retard.duree_minutes ? String(retard.duree_minutes) : '',
        motif: retard.motif ?? '',
        justifie: retard.justifie,
      })
    }
  }, [retard, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = {
        ...values,
        heure_arrivee: values.heure_arrivee || null,
        duree_minutes: values.duree_minutes ? Number(values.duree_minutes) : null,
      }
      return isEdit ? updateRetard(id, payload) : createRetard(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['retards'] })
      navigate('/retards')
    },
  })

  return (
    <div className="max-w-xl">
      <Link to="/retards" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? 'Modifier le retard' : 'Signaler un retard'}
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

        <FormSection icon={Clock} title="Détails du retard">
          <div className="grid grid-cols-3 gap-4">
            <Input type="date" icon={CalendarDays} label="Date" error={errors.date_retard?.message} {...register('date_retard')} />
            <Input
              type="time"
              icon={Clock}
              label="Heure d'arrivée"
              error={errors.heure_arrivee?.message}
              {...register('heure_arrivee')}
            />
            <Input
              type="number"
              min="0"
              icon={Hash}
              label="Durée (min)"
              error={errors.duree_minutes?.message}
              {...register('duree_minutes')}
            />
          </div>

          <Input icon={FileText} label="Motif" error={errors.motif?.message} {...register('motif')} />

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="rounded border-slate-300" {...register('justifie')} />
            Retard justifié
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
