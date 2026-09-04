import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, School, Hash, ListChecks, CalendarDays, User } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import { fetchClasse, createClasse, updateClasse, archiverClasse } from './classesApi'
import { fetchNiveaux, fetchAnneesScolaires, fetchEnseignants } from '../reference/referenceApi'
import ClasseIntervenantsPanel from './ClasseIntervenantsPanel'

const schema = z.object({
  nom: z.string().min(1, 'Le nom est requis').max(100),
  capacite: z.string().optional(),
  niveau_id: z.string().min(1, 'Le niveau est requis'),
  annee_scolaire_id: z.string().min(1, "L'année scolaire est requise"),
  enseignant_principal_id: z.string().min(1, "L'enseignant titulaire est obligatoire pour une classe de primaire"),
})

export default function ClasseFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: niveaux } = useQuery({ queryKey: ['niveaux'], queryFn: fetchNiveaux })
  const { data: anneesScolaires } = useQuery({ queryKey: ['annees-scolaires'], queryFn: fetchAnneesScolaires })
  const { data: enseignants } = useQuery({ queryKey: ['enseignants', 'all'], queryFn: fetchEnseignants })

  const { data: classe } = useQuery({
    queryKey: ['classes', id],
    queryFn: () => fetchClasse(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (classe) {
      reset({
        nom: classe.nom,
        capacite: classe.capacite ? String(classe.capacite) : '',
        niveau_id: classe.niveau_id ? String(classe.niveau_id) : '',
        annee_scolaire_id: classe.annee_scolaire_id ? String(classe.annee_scolaire_id) : '',
        enseignant_principal_id: classe.enseignant_principal_id ? String(classe.enseignant_principal_id) : '',
      })
    }
  }, [classe, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = {
        nom: values.nom,
        capacite: values.capacite ? Number(values.capacite) : null,
        niveau_id: values.niveau_id || null,
        annee_scolaire_id: values.annee_scolaire_id || null,
        enseignant_principal_id: values.enseignant_principal_id || null,
      }
      return isEdit ? updateClasse(id, payload) : createClasse(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['classes'] })
      navigate('/classes')
    },
  })

  const archiveMutation = useMutation({
    mutationFn: () => archiverClasse(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['classes', id] }),
    onError: (error) => alert(error.response?.data?.message ?? "Archivage impossible."),
  })

  return (
    <div className="max-w-xl">
      <Link to="/classes" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? 'Modifier la classe' : 'Ajouter une classe'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={School} title="Informations générales">
          <div className="grid grid-cols-2 gap-4">
            <Input icon={School} label="Nom de la classe" error={errors.nom?.message} {...register('nom')} />
            <Input type="number" min="0" icon={Hash} label="Capacité" error={errors.capacite?.message} {...register('capacite')} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Select icon={ListChecks} label="Niveau" error={errors.niveau_id?.message} {...register('niveau_id')}>
              <option value="">— Sélectionner —</option>
              {niveaux?.map((niveau) => (
                <option key={niveau.id} value={niveau.id}>
                  {niveau.libelle}
                </option>
              ))}
            </Select>
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
          </div>
        </FormSection>

        <FormSection icon={User} title="Affectation">
          <Select
            icon={User}
            label="Enseignant titulaire"
            error={errors.enseignant_principal_id?.message}
            {...register('enseignant_principal_id')}
          >
            <option value="">— Sélectionner —</option>
            {enseignants?.map((enseignant) => (
              <option key={enseignant.id} value={enseignant.id}>
                {enseignant.prenom} {enseignant.nom}
              </option>
            ))}
          </Select>
        </FormSection>

        {mutation.isError && (
          <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">
            Une erreur est survenue. Vérifiez les champs et réessayez.
          </p>
        )}

        <div className="flex items-center justify-between gap-2 pt-2">
          {isEdit && classe && !classe.archivee && (
            <Button
              type="button"
              variant="outline"
              onClick={() => confirm('Archiver cette classe ?') && archiveMutation.mutate()}
              disabled={archiveMutation.isPending}
            >
              Archiver
            </Button>
          )}
          {isEdit && classe?.archivee && (
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-500">Archivée</span>
          )}
          <div className="ml-auto">
            <Button type="submit" disabled={isSubmitting || mutation.isPending}>
              {isEdit ? 'Enregistrer' : 'Créer'}
            </Button>
          </div>
        </div>
      </form>

      {isEdit && classe && (
        <div className="mt-6">
          <ClasseIntervenantsPanel classe={classe} />
        </div>
      )}
    </div>
  )
}
