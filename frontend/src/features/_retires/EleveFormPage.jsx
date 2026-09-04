import { useEffect, useState } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  Check,
  User,
  Hash,
  CalendarDays,
  MapPin,
  Globe,
  Users,
  FileText,
  Mail,
  Phone,
  School,
  ListChecks,
  BookOpen,
  Briefcase,
} from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import { fetchEleve, createEleve, updateEleve } from './elevesApi'
import { fetchAllClasses } from '../reference/referenceApi'
import EleveParentsPanel from './EleveParentsPanel'

const schema = z.object({
  matricule: z.string().min(1, 'Le matricule est requis').max(30),
  nom: z.string().min(1, 'Le nom est requis').max(100),
  prenom: z.string().min(1, 'Le prénom est requis').max(100),
  date_naissance: z.string().min(1, 'La date de naissance est requise'),
  numero_acte_naissance: z.string().optional(),
  acte_delivre_par: z.string().optional(),
  lieu_naissance: z.string().optional(),
  pays_naissance: z.string().optional(),
  nationalite: z.string().optional(),
  sexe: z.enum(['M', 'F'], { message: 'Le sexe est requis' }),
  classe_id: z.string().optional(),
  statut: z.string().optional(),
  redoublant: z.boolean().optional(),
  type_eleve: z.string().optional(),
  lv2: z.string().optional(),
  situation_familiale: z.string().optional(),
  adresse: z.string().optional(),
  ville: z.string().optional(),
  commune: z.string().optional(),
  quartier: z.string().optional(),
  region: z.string().optional(),
  email: z.union([z.literal(''), z.string().email('Email invalide')]).optional(),
  telephone: z.string().optional(),
  pere_tuteur_lien: z.string().optional(),
  pere_nom: z.string().optional(),
  pere_prenom: z.string().optional(),
  pere_telephone: z.string().optional(),
  pere_email: z.union([z.literal(''), z.string().email('Email invalide')]).optional(),
  pere_profession: z.string().optional(),
  mere_nom: z.string().optional(),
  mere_prenom: z.string().optional(),
  mere_telephone: z.string().optional(),
  mere_email: z.union([z.literal(''), z.string().email('Email invalide')]).optional(),
  mere_profession: z.string().optional(),
})

const STEPS = ['Identité', 'Coordonnées', 'Scolarité', 'Père / Tuteur']

const STEP_FIELDS = [
  [
    'matricule', 'nom', 'prenom', 'sexe', 'nationalite', 'situation_familiale',
    'date_naissance', 'lieu_naissance', 'pays_naissance', 'numero_acte_naissance', 'acte_delivre_par',
  ],
  ['adresse', 'ville', 'commune', 'quartier', 'region', 'email', 'telephone'],
  ['classe_id', 'statut', 'lv2', 'type_eleve', 'redoublant'],
  [
    'pere_tuteur_lien', 'pere_nom', 'pere_prenom', 'pere_telephone', 'pere_email', 'pere_profession',
    'mere_nom', 'mere_prenom', 'mere_telephone', 'mere_email', 'mere_profession',
  ],
]

function SectionTitle({ children }) {
  return <h2 className="mb-3 mt-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{children}</h2>
}

function StepIndicator({ step }) {
  return (
    <div className="mb-6 flex items-center">
      {STEPS.map((label, index) => (
        <div key={label} className="flex flex-1 items-center last:flex-none">
          <div className="flex items-center gap-2">
            <div
              className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                index < step
                  ? 'bg-primary-600 text-white'
                  : index === step
                    ? 'bg-primary-100 text-primary-700 ring-2 ring-primary-500'
                    : 'bg-slate-100 text-slate-400'
              }`}
            >
              {index < step ? <Check size={14} /> : index + 1}
            </div>
            <span className={`text-sm font-medium ${index <= step ? 'text-slate-800' : 'text-slate-400'}`}>
              {label}
            </span>
          </div>
          {index < STEPS.length - 1 && <div className="mx-3 h-px flex-1 bg-slate-200" />}
        </div>
      ))}
    </div>
  )
}

export default function EleveFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [step, setStep] = useState(0)

  const { data: classes } = useQuery({
    queryKey: ['classes', 'all'],
    queryFn: fetchAllClasses,
  })

  const { data: eleve } = useQuery({
    queryKey: ['eleves', id],
    queryFn: () => fetchEleve(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    trigger,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema), defaultValues: { statut: 'actif', pere_tuteur_lien: 'pere' } })

  useEffect(() => {
    if (eleve) {
      reset({
        matricule: eleve.matricule,
        nom: eleve.nom,
        prenom: eleve.prenom,
        date_naissance: eleve.date_naissance?.slice(0, 10),
        numero_acte_naissance: eleve.numero_acte_naissance ?? '',
        acte_delivre_par: eleve.acte_delivre_par ?? '',
        lieu_naissance: eleve.lieu_naissance ?? '',
        pays_naissance: eleve.pays_naissance ?? '',
        nationalite: eleve.nationalite ?? '',
        sexe: eleve.sexe,
        classe_id: eleve.classe_id ? String(eleve.classe_id) : '',
        statut: eleve.statut,
        redoublant: eleve.redoublant ?? false,
        type_eleve: eleve.type_eleve ?? '',
        lv2: eleve.lv2 ?? '',
        situation_familiale: eleve.situation_familiale ?? '',
        adresse: eleve.adresse ?? '',
        ville: eleve.ville ?? '',
        commune: eleve.commune ?? '',
        quartier: eleve.quartier ?? '',
        region: eleve.region ?? '',
        email: eleve.email ?? '',
        telephone: eleve.telephone ?? '',
      })
    }
  }, [eleve, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = { ...values, classe_id: values.classe_id || null, email: values.email || null }
      return isEdit ? updateEleve(id, payload) : createEleve(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['eleves'] })
      navigate('/eleves')
    },
  })

  const handleNext = async () => {
    const valid = await trigger(STEP_FIELDS[step])
    if (valid) setStep((s) => s + 1)
  }

  return (
    <div className="max-w-3xl">
      <Link to="/eleves" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? "Modifier l'élève" : 'Ajouter un élève'}
      </h1>

      {!isEdit && <StepIndicator step={step} />}

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        {(isEdit || step === 0) && (
          <FormSection icon={User} title="Identité">
            <div className="grid grid-cols-3 gap-4">
              <Input icon={Hash} label="Matricule" error={errors.matricule?.message} {...register('matricule')} />
              <Input icon={User} label="Nom" error={errors.nom?.message} {...register('nom')} />
              <Input icon={User} label="Prénom" error={errors.prenom?.message} {...register('prenom')} />
            </div>
            <div className="grid grid-cols-3 gap-4">
              <Select icon={User} label="Sexe" error={errors.sexe?.message} {...register('sexe')}>
                <option value="">—</option>
                <option value="M">Masculin</option>
                <option value="F">Féminin</option>
              </Select>
              <Input icon={Globe} label="Nationalité" error={errors.nationalite?.message} {...register('nationalite')} />
              <Input
                icon={Users}
                label="Situation familiale"
                error={errors.situation_familiale?.message}
                {...register('situation_familiale')}
              />
            </div>

            <SectionTitle>État civil</SectionTitle>
            <div className="grid grid-cols-3 gap-4">
              <Input
                type="date"
                icon={CalendarDays}
                label="Date de naissance"
                error={errors.date_naissance?.message}
                {...register('date_naissance')}
              />
              <Input icon={MapPin} label="Lieu de naissance" error={errors.lieu_naissance?.message} {...register('lieu_naissance')} />
              <Input icon={Globe} label="Pays de naissance" error={errors.pays_naissance?.message} {...register('pays_naissance')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input
                icon={Hash}
                label="N° acte de naissance"
                error={errors.numero_acte_naissance?.message}
                {...register('numero_acte_naissance')}
              />
              <Input icon={FileText} label="Délivré par" error={errors.acte_delivre_par?.message} {...register('acte_delivre_par')} />
            </div>
          </FormSection>
        )}

        {(isEdit || step === 1) && (
          <FormSection icon={MapPin} title="Coordonnées">
            <Input icon={MapPin} label="Adresse" error={errors.adresse?.message} {...register('adresse')} />
            <div className="grid grid-cols-3 gap-4">
              <Input icon={MapPin} label="Ville" error={errors.ville?.message} {...register('ville')} />
              <Input icon={MapPin} label="Commune" error={errors.commune?.message} {...register('commune')} />
              <Input icon={MapPin} label="Quartier" error={errors.quartier?.message} {...register('quartier')} />
            </div>
            <div className="grid grid-cols-3 gap-4">
              <Input icon={MapPin} label="Région" error={errors.region?.message} {...register('region')} />
              <Input icon={Mail} label="Email" error={errors.email?.message} {...register('email')} />
              <Input icon={Phone} label="Téléphone" error={errors.telephone?.message} {...register('telephone')} />
            </div>
          </FormSection>
        )}

        {(isEdit || step === 2) && (
          <FormSection icon={School} title="Scolarité">
            <div className="grid grid-cols-3 gap-4">
              <Select icon={School} label="Classe" error={errors.classe_id?.message} {...register('classe_id')}>
                <option value="">— Aucune —</option>
                {classes?.map((classe) => (
                  <option key={classe.id} value={classe.id}>
                    {classe.nom}
                  </option>
                ))}
              </Select>
              <Select icon={ListChecks} label="Statut" {...register('statut')}>
                <option value="actif">Actif</option>
                <option value="transfere">Transféré</option>
                <option value="radie">Radié</option>
              </Select>
              <Input icon={BookOpen} label="LV2" error={errors.lv2?.message} {...register('lv2')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input
                icon={ListChecks}
                label="Type d'élève"
                placeholder="interne, externe, demi-pensionnaire…"
                error={errors.type_eleve?.message}
                {...register('type_eleve')}
              />
              <label className="mt-6 flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" className="rounded border-slate-300" {...register('redoublant')} />
                Redoublant
              </label>
            </div>
          </FormSection>
        )}

        {!isEdit && step === 3 && (
          <FormSection icon={Users} title="Père / Tuteur">
            <div className="grid grid-cols-3 gap-4">
              <Select icon={ListChecks} label="Lien" {...register('pere_tuteur_lien')}>
                <option value="pere">Père</option>
                <option value="tuteur">Tuteur</option>
              </Select>
              <Input icon={User} label="Nom" error={errors.pere_nom?.message} {...register('pere_nom')} />
              <Input icon={User} label="Prénom" error={errors.pere_prenom?.message} {...register('pere_prenom')} />
            </div>
            <div className="grid grid-cols-3 gap-4">
              <Input icon={Phone} label="Téléphone" {...register('pere_telephone')} />
              <Input icon={Mail} label="Email" error={errors.pere_email?.message} {...register('pere_email')} />
              <Input icon={Briefcase} label="Profession" {...register('pere_profession')} />
            </div>

            <SectionTitle>Mère</SectionTitle>
            <div className="grid grid-cols-3 gap-4">
              <Input icon={User} label="Nom" error={errors.mere_nom?.message} {...register('mere_nom')} />
              <Input icon={User} label="Prénom" error={errors.mere_prenom?.message} {...register('mere_prenom')} />
              <Input icon={Phone} label="Téléphone" {...register('mere_telephone')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Mail} label="Email" error={errors.mere_email?.message} {...register('mere_email')} />
              <Input icon={Briefcase} label="Profession" {...register('mere_profession')} />
            </div>
          </FormSection>
        )}

        {mutation.isError && (
          <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">
            Une erreur est survenue. Vérifiez les champs et réessayez.
          </p>
        )}

        <div className="flex justify-between gap-2 pt-2">
          {!isEdit && step > 0 ? (
            <Button type="button" variant="outline" onClick={() => setStep((s) => s - 1)}>
              Précédent
            </Button>
          ) : (
            <span />
          )}

          {isEdit ? (
            <Button type="submit" disabled={isSubmitting || mutation.isPending}>
              Enregistrer
            </Button>
          ) : step < STEPS.length - 1 ? (
            <Button type="button" onClick={handleNext}>
              Suivant
            </Button>
          ) : (
            <Button type="submit" disabled={isSubmitting || mutation.isPending}>
              Valider
            </Button>
          )}
        </div>
      </form>

      {isEdit && eleve && (
        <div className="mt-6 space-y-6">
          <EleveParentsPanel eleve={eleve} />
        </div>
      )}
    </div>
  )
}
