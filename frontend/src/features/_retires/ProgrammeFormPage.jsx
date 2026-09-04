import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ListChecks, BookOpen, CalendarDays, FileText } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import FormSection from '../../components/ui/FormSection'
import { fetchProgramme, createProgramme, updateProgramme } from './programmesApi'
import { fetchNiveaux, fetchMatieres, fetchAnneesScolaires } from '../reference/referenceApi'

const schema = z.object({
  niveau_id: z.string().min(1, 'Le niveau est requis'),
  matiere_id: z.string().min(1, 'La matière est requise'),
  annee_scolaire_id: z.string().min(1, "L'année scolaire est requise"),
  titre: z.string().min(1, 'Le titre est requis').max(150),
  contenu: z.string().optional(),
})

export default function ProgrammeFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: niveaux } = useQuery({ queryKey: ['niveaux'], queryFn: fetchNiveaux })
  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })
  const { data: anneesScolaires } = useQuery({ queryKey: ['annees-scolaires'], queryFn: fetchAnneesScolaires })

  const { data: programme } = useQuery({
    queryKey: ['programmes', id],
    queryFn: () => fetchProgramme(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (programme) {
      reset({
        niveau_id: String(programme.niveau_id),
        matiere_id: String(programme.matiere_id),
        annee_scolaire_id: String(programme.annee_scolaire_id),
        titre: programme.titre,
        contenu: programme.contenu ?? '',
      })
    }
  }, [programme, reset])

  const mutation = useMutation({
    mutationFn: (values) => (isEdit ? updateProgramme(id, values) : createProgramme(values)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['programmes'] })
      navigate('/programmes')
    },
  })

  return (
    <div className="max-w-xl">
      <Link to="/programmes" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? 'Modifier le programme' : 'Ajouter un programme'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={BookOpen} title="Informations générales">
          <div className="grid grid-cols-2 gap-4">
            <Select icon={ListChecks} label="Niveau" error={errors.niveau_id?.message} {...register('niveau_id')}>
              <option value="">— Sélectionner —</option>
              {niveaux?.map((niveau) => (
                <option key={niveau.id} value={niveau.id}>
                  {niveau.libelle}
                </option>
              ))}
            </Select>
            <Select icon={BookOpen} label="Matière" error={errors.matiere_id?.message} {...register('matiere_id')}>
              <option value="">— Sélectionner —</option>
              {matieres?.map((matiere) => (
                <option key={matiere.id} value={matiere.id}>
                  {matiere.libelle}
                </option>
              ))}
            </Select>
          </div>
          <Select
            icon={CalendarDays}
            label="Année scolaire"
            error={errors.annee_scolaire_id?.message}
            {...register('annee_scolaire_id')}
          >
            <option value="">— Sélectionner —</option>
            {anneesScolaires?.map((annee) => (
              <option key={annee.id} value={annee.id}>
                {annee.libelle}
              </option>
            ))}
          </Select>
          <Input icon={FileText} label="Titre" error={errors.titre?.message} {...register('titre')} />
        </FormSection>

        <FormSection icon={FileText} title="Contenu">
          <Textarea icon={FileText} label="Contenu / objectifs" rows={6} error={errors.contenu?.message} {...register('contenu')} />
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
