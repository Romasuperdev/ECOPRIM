import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CalendarDays, School, BookOpen, User, ListChecks, FileText, ClipboardList } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import FormSection from '../../components/ui/FormSection'
import { fetchSeance, createSeance, updateSeance } from './seancesApi'
import { fetchAllClasses, fetchMatieres, fetchEnseignants } from '../reference/referenceApi'
import { fetchProgrammesByMatiere } from '../programmes/programmesApi'

const schema = z.object({
  classe_id: z.string().min(1, 'La classe est requise'),
  matiere_id: z.string().min(1, 'La matière est requise'),
  programme_id: z.string().optional(),
  enseignant_id: z.string().optional(),
  date_seance: z.string().min(1, 'La date est requise'),
  contenu: z.string().min(1, 'Le contenu est requis'),
  devoirs: z.string().optional(),
})

export default function SeanceFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })
  const { data: enseignants } = useQuery({ queryKey: ['enseignants', 'all'], queryFn: fetchEnseignants })

  const { data: seance } = useQuery({
    queryKey: ['seances', id],
    queryFn: () => fetchSeance(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema) })

  const matiereId = watch('matiere_id')
  const { data: programmes } = useQuery({
    queryKey: ['programmes', 'par-matiere', matiereId],
    queryFn: () => fetchProgrammesByMatiere(matiereId),
    enabled: Boolean(matiereId),
  })

  useEffect(() => {
    if (seance) {
      reset({
        classe_id: String(seance.classe_id),
        matiere_id: String(seance.matiere_id),
        programme_id: seance.programme_id ? String(seance.programme_id) : '',
        enseignant_id: seance.enseignant_id ? String(seance.enseignant_id) : '',
        date_seance: seance.date_seance,
        contenu: seance.contenu,
        devoirs: seance.devoirs ?? '',
      })
    }
  }, [seance, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = {
        ...values,
        enseignant_id: values.enseignant_id || null,
        programme_id: values.programme_id || null,
      }
      return isEdit ? updateSeance(id, payload) : createSeance(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['seances'] })
      navigate('/seances')
    },
  })

  return (
    <div className="max-w-xl">
      <Link to="/seances" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? 'Modifier la séance' : 'Ajouter une séance'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={CalendarDays} title="Séance">
          <div className="grid grid-cols-2 gap-4">
            <Select icon={School} label="Classe" error={errors.classe_id?.message} {...register('classe_id')}>
              <option value="">— Sélectionner —</option>
              {classes?.map((classe) => (
                <option key={classe.id} value={classe.id}>
                  {classe.nom}
                </option>
              ))}
            </Select>
            <Input
              type="date"
              icon={CalendarDays}
              label="Date"
              error={errors.date_seance?.message}
              {...register('date_seance')}
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Select icon={BookOpen} label="Matière" error={errors.matiere_id?.message} {...register('matiere_id')}>
              <option value="">— Sélectionner —</option>
              {matieres?.map((matiere) => (
                <option key={matiere.id} value={matiere.id}>
                  {matiere.libelle}
                </option>
              ))}
            </Select>
            <Select icon={User} label="Enseignant" error={errors.enseignant_id?.message} {...register('enseignant_id')}>
              <option value="">— Non renseigné —</option>
              {enseignants?.map((enseignant) => (
                <option key={enseignant.id} value={enseignant.id}>
                  {enseignant.prenom} {enseignant.nom}
                </option>
              ))}
            </Select>
          </div>
        </FormSection>

        <FormSection icon={FileText} title="Contenu">
          <Select
            icon={ListChecks}
            label="Point du programme (optionnel)"
            error={errors.programme_id?.message}
            {...register('programme_id')}
          >
            <option value="">— Aucun —</option>
            {programmes?.map((programme) => (
              <option key={programme.id} value={programme.id}>
                {programme.titre}
              </option>
            ))}
          </Select>

          <Textarea icon={FileText} label="Contenu de la séance" error={errors.contenu?.message} {...register('contenu')} />
          <Textarea
            icon={ClipboardList}
            label="Devoirs à faire"
            rows={2}
            error={errors.devoirs?.message}
            {...register('devoirs')}
          />
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
