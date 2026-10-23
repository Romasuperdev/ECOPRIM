import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { UserCog, UserPlus } from 'lucide-react'
import Input from '../../../components/ui/Input'
import Select from '../../../components/ui/Select'
import SelecteurEleves from '../SelecteurEleves'
import { useAuthStore } from '../../../store/authStore'
import { createUtilisateur, fetchAllEtablissements, fetchRoles, fetchUtilisateur, updateUtilisateur } from '../adminApi'
import { GrilleChamps, PageFormulaire, flash, messageApi } from './kit'

const VIDE = {
  login: '', mot_de_passe: '', nom: '', prenom: '', email: '',
  matricule: '', etab: '', contact: '', profil: '', code_app: '', super_admin: false,
  // Rôle & affectation, posés dans le même geste que la création (UserController::store) :
  // c'est ici qu'on fait d'un compte un Enseignant ou un Parent.
  etablissement_code: '', role_id: '',
}

const Rubrique = ({ children }) => (
  <p className="text-xs font-bold uppercase tracking-wide text-muted">{children}</p>
)

/**
 * Nouvel utilisateur / modification — un formulaire sur sa propre page, comme
 * console/rh-users/create et edit dans parcauto. En modification, le mot de passe laissé
 * vide est conservé ; le rôle et l'affectation se gèrent ensuite sur la fiche du compte.
 */
export default function UtilisateurFormPage() {
  const { id } = useParams()
  const { data: compte, error } = useQuery({
    queryKey: ['utilisateurs', id], queryFn: () => fetchUtilisateur(id), enabled: Boolean(id), retry: false,
  })

  if (!id) return <Formulaire key="nouveau" initial={{ ...VIDE }} />

  if (error || !compte) {
    return (
      <PageFormulaire icon={UserCog} titre="Modifier l’Utilisateur" retour="/admin/utilisateurs" onSubmit={() => {}} enCours>
        <p className="py-6 text-center text-muted">
          {error ? messageApi(error, 'Utilisateur introuvable.') : 'Chargement…'}
        </p>
      </PageFormulaire>
    )
  }

  // Le formulaire part du compte chargé ; ensuite il appartient à la saisie.
  const initial = { ...VIDE }
  for (const k of Object.keys(VIDE)) if (compte[k] != null) initial[k] = compte[k]

  return <Formulaire key={id} id={id} initial={{ ...initial, mot_de_passe: '' }} />
}

function Formulaire({ id, initial }) {
  const edition = Boolean(id)
  const navigate = useNavigate()
  const qc = useQueryClient()
  const superAdmin = useAuthStore((s) => s.superAdmin)
  const [form, setForm] = useState(initial)
  const [enfants, setEnfants] = useState([])
  const [erreurs, setErreurs] = useState({})
  const [erreur, setErreur] = useState(null)

  const { data: etablissements } = useQuery({ queryKey: ['etabs-tous'], queryFn: () => fetchAllEtablissements() })
  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles, enabled: !edition })

  const estRoleParent = roles?.find((r) => String(r.id) === String(form?.role_id))?.code === 'parent'

  const enregistrer = useMutation({
    mutationFn: (v) => (edition
      ? updateUtilisateur(id, v)
      : createUtilisateur({ ...v, eleves: estRoleParent ? enfants.map((e) => e.matricule) : undefined })),
    onSuccess: (u) => {
      qc.invalidateQueries({ queryKey: ['utilisateurs'] })
      flash.succes(edition ? `L’utilisateur ${u.login} a été modifié.` : `L’utilisateur ${u.login} a été créé.`)
      navigate('/admin/utilisateurs')
    },
    onError: (e) => {
      setErreurs(e?.response?.data?.errors ?? {})
      setErreur(messageApi(e))
    },
  })

  const titre = edition ? 'Modifier l’Utilisateur' : 'Nouvel Utilisateur'
  const icone = edition ? UserCog : UserPlus

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const saisie = (k, label, props = {}) => (
    <Input label={label} value={form[k] ?? ''} onChange={(e) => champ(k, e.target.value)} error={erreurs[k]?.[0]} {...props} />
  )
  const incomplet = !form.login || !form.nom || (!edition && !form.mot_de_passe)
    || (!edition && !superAdmin && (!form.etablissement_code || !form.role_id))

  return (
    <PageFormulaire
      icon={icone}
      titre={titre}
      retour="/admin/utilisateurs"
      onSubmit={() => { if (!incomplet) { setErreur(null); enregistrer.mutate(form) } else setErreur('Renseignez les champs marqués d’un astérisque.') }}
      enCours={enregistrer.isPending}
      erreur={erreur}
      libelle={edition ? 'Enregistrer' : 'Créer'}
    >
      <Rubrique>Connexion</Rubrique>
      <GrilleChamps>
        {saisie('login', 'Login *', { autoComplete: 'off' })}
        {saisie('mot_de_passe', edition ? 'Mot de passe (laisser vide pour conserver)' : 'Mot de passe *', { type: 'password', autoComplete: 'new-password' })}
      </GrilleChamps>

      <Rubrique>Identité</Rubrique>
      <GrilleChamps>
        {saisie('nom', 'Nom *')}
        {saisie('prenom', 'Prénom')}
        {saisie('email', 'Email', { type: 'email' })}
        {saisie('matricule', 'Matricule')}
        {saisie('contact', 'Contact')}
      </GrilleChamps>

      <Rubrique>Rattachement</Rubrique>
      <GrilleChamps>
        <Select label="Établissement" value={form.etab ?? ''} onChange={(e) => champ('etab', e.target.value)} error={erreurs.etab?.[0]}>
          <option value="">— Aucun —</option>
          {etablissements?.map((e) => <option key={e.code} value={e.code}>{e.intitule} ({e.code})</option>)}
          {/* Valeur héritée qui ne correspond à aucun établissement connu : on la garde
              pour ne pas l'effacer en enregistrant. */}
          {form.etab && etablissements && !etablissements.some((e) => e.code === form.etab) && (
            <option value={form.etab}>{form.etab} (inconnu)</option>
          )}
        </Select>
        {saisie('profil', 'Profil')}
        {saisie('code_app', 'Application (code)')}
      </GrilleChamps>

      {/* Le drapeau Super Admin ouvre toute la console : seul un Super Admin le confère
          (le serveur le refuse de toute façon aux autres). */}
      {superAdmin && (
        <label className="flex items-center gap-2 text-sm font-medium text-heading">
          <input type="checkbox" checked={!!form.super_admin} onChange={(e) => champ('super_admin', e.target.checked)} />
          Super Admin (accès complet à la console, toutes sociétés)
        </label>
      )}

      {!edition && (
        <>
          <Rubrique>Rôle (Enseignant, Parent, Secrétaire…)</Rubrique>
          <p className="-mt-2 text-xs text-muted">
            Un compte affecté du SEUL rôle Enseignant ou Parent est dirigé vers son portail
            restreint au lieu de l’application complète.
            {!superAdmin && ' Obligatoire pour retrouver ensuite le compte dans votre liste.'}
          </p>
          <GrilleChamps>
            <Select
              label={superAdmin ? 'Établissement' : 'Établissement *'} value={form.etablissement_code}
              onChange={(e) => champ('etablissement_code', e.target.value)} error={erreurs.etablissement_code?.[0]}
            >
              <option value="">— Choisir —</option>
              {etablissements?.filter((e) => e.repris && e.actif).map((e) => (
                <option key={e.code} value={e.code}>{e.intitule} ({e.code})</option>
              ))}
            </Select>
            <Select
              label={superAdmin ? 'Rôle' : 'Rôle *'} value={form.role_id}
              onChange={(e) => champ('role_id', e.target.value)} error={erreurs.role_id?.[0]}
            >
              <option value="">— Choisir —</option>
              {roles?.map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
            </Select>
          </GrilleChamps>
          {estRoleParent && (
            <div className="max-w-md">
              <SelecteurEleves selection={enfants} onChange={setEnfants} />
            </div>
          )}
        </>
      )}
    </PageFormulaire>
  )
}
