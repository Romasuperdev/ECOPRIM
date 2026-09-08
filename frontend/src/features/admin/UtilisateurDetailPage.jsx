import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, History, Trash2, X } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import {
  createAffectation,
  definirEnfantsAffectation,
  fetchAllEtablissements,
  fetchRoles,
  fetchUtilisateur,
  rechercherEleves,
  terminerAffectation,
} from './adminApi'

function Champ({ label, value }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="text-sm text-slate-800">{value || '—'}</dd>
    </div>
  )
}

/**
 * Recherche et sélection d'un ou plusieurs élèves — utilisé pour rattacher les enfants
 * d'un compte du rôle Parent. Recherche par nom ou matricule (EleveController::index).
 */
function SelecteurEleves({ selection, onChange }) {
  const [recherche, setRecherche] = useState('')
  const { data: resultats } = useQuery({
    queryKey: ['recherche-eleves', recherche],
    queryFn: () => rechercherEleves(recherche),
    enabled: recherche.trim().length >= 2,
  })

  const ajouter = (eleve) => {
    if (! selection.some((e) => e.matricule === eleve.matricule)) {
      onChange([...selection, eleve])
    }
    setRecherche('')
  }
  const retirer = (matricule) => onChange(selection.filter((e) => e.matricule !== matricule))

  return (
    <div>
      <Input
        label="Élèves rattachés"
        placeholder="Rechercher par nom ou matricule…"
        value={recherche}
        onChange={(e) => setRecherche(e.target.value)}
      />
      {resultats?.length > 0 && (
        <div className="mt-1 max-h-40 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-sm">
          {resultats.map((e) => (
            <button
              key={e.matricule} type="button" onClick={() => ajouter(e)}
              className="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
            >
              {e.prenom} {e.nom} <span className="text-slate-400">({e.matricule})</span>
            </button>
          ))}
        </div>
      )}
      {selection.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {selection.map((e) => (
            <span key={e.matricule} className="flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">
              {`${e.prenom ?? ''} ${e.nom ?? ''}`.trim() || e.matricule}
              <button type="button" onClick={() => retirer(e.matricule)} className="text-slate-400 hover:text-red-500">
                <X size={12} />
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

/** Chips + édition des enfants d'une affectation déjà créée (rôle Parent). */
function EnfantsAffectation({ affectation, onSaved }) {
  const [edition, setEdition] = useState(false)
  const [selection, setSelection] = useState(
    (affectation.eleves ?? []).map((e) => ({ matricule: e.eleve_matricule }))
  )

  const enregistrer = useMutation({
    mutationFn: () => definirEnfantsAffectation(affectation.id, selection.map((e) => e.matricule)),
    onSuccess: () => { setEdition(false); onSaved() },
  })

  if (! edition) {
    return (
      <div className="mt-1 flex flex-wrap items-center gap-1.5">
        {(affectation.eleves ?? []).length === 0 && <span className="text-xs text-slate-400">Aucun enfant rattaché.</span>}
        {(affectation.eleves ?? []).map((e) => (
          <span key={e.eleve_matricule} className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
            {e.eleve_matricule}
          </span>
        ))}
        <button type="button" onClick={() => setEdition(true)} className="text-xs text-primary-600 underline">
          Gérer
        </button>
      </div>
    )
  }

  return (
    <div className="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
      <SelecteurEleves selection={selection} onChange={setSelection} />
      <div className="mt-2 flex justify-end gap-2">
        <Button variant="outline" className="!px-3 !py-1 text-xs" onClick={() => setEdition(false)}>Annuler</Button>
        <Button className="!px-3 !py-1 text-xs" disabled={enregistrer.isPending} onClick={() => enregistrer.mutate()}>
          {enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer'}
        </Button>
      </div>
    </div>
  )
}

export default function UtilisateurDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [nouvelEtab, setNouvelEtab] = useState('')
  const [nouveauRole, setNouveauRole] = useState('')
  const [enfants, setEnfants] = useState([])
  const [erreur, setErreur] = useState(null)

  const { data: user, isLoading } = useQuery({ queryKey: ['utilisateurs', id], queryFn: () => fetchUtilisateur(id) })
  const societeCode = user?.societe_code || undefined
  const { data: etabs } = useQuery({ queryKey: ['etabs-affectation', societeCode], queryFn: () => fetchAllEtablissements(societeCode), enabled: !!user })
  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })

  const roleChoisi = roles?.find((r) => String(r.id) === String(nouveauRole))
  const estRoleParent = roleChoisi?.code === 'parent'

  const invalider = () => qc.invalidateQueries({ queryKey: ['utilisateurs', id] })

  const ajouter = useMutation({
    mutationFn: () => createAffectation({
      rh_user_id: Number(id), etablissement_code: nouvelEtab, role_id: Number(nouveauRole),
      eleves: estRoleParent ? enfants.map((e) => e.matricule) : undefined,
    }),
    onSuccess: () => { invalider(); setNouvelEtab(''); setNouveauRole(''); setEnfants([]); setErreur(null) },
    onError: (e) => {
      const err = e?.response?.data?.errors
      setErreur(err ? Object.values(err).flat()[0] : (e?.response?.data?.message ?? 'Erreur'))
    },
  })

  const retirer = useMutation({
    mutationFn: (affId) => terminerAffectation(affId),
    onSuccess: invalider,
  })

  if (isLoading) return <p className="text-slate-400">Chargement…</p>
  if (!user) return <p className="text-slate-400">Utilisateur introuvable.</p>

  const affectations = user.affectations ?? []

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Link to="/admin/utilisateurs" className="rounded p-1.5 text-slate-500 hover:bg-slate-100"><ArrowLeft size={18} /></Link>
        <h1 className="text-2xl font-bold text-slate-800">{user.name}</h1>
        {/* La traçabilité du compte se lit depuis sa fiche, filtrée sur ses identifiants. */}
        <Link to={`/admin/utilisateurs/${user.id}/tracabilite`} className="ml-auto">
          <Button variant="outline">
            <History size={16} className="mr-1.5 inline" />
            Traçabilité
          </Button>
        </Link>
      </div>

      <section className="rounded-xl border border-slate-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-slate-800">Compte (RH_USER — lecture seule)</h2>
        <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
          <Champ label="Login" value={user.login} />
          <Champ label="Matricule" value={user.matricule} />
          <Champ label="Email" value={user.email} />
          <Champ label="Société de rattachement" value={user.societe_code} />
          <Champ label="Actif" value={user.actif ? 'Oui' : 'Non'} />
        </dl>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-800">Rôles par établissement</h2>
        </div>
        <p className="mb-4 text-xs text-slate-400">
          Un compte affecté du SEUL rôle Enseignant ou Parent est dirigé vers son portail
          restreint (ses classes, ou les enfants rattachés ci-dessous) au lieu de
          l'application complète.
        </p>

        {affectations.length ? (
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-slate-500">
              <tr>
                <th className="py-2 font-medium">Établissement</th>
                <th className="py-2 font-medium">Société</th>
                <th className="py-2 font-medium">Rôle</th>
                <th className="py-2 font-medium text-right"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {affectations.map((a) => (
                <tr key={a.id}>
                  <td className="py-2 align-top text-slate-800">{a.etablissement?.intitule ?? a.etablissement_code}</td>
                  <td className="py-2 align-top text-slate-600">{a.societe_code}</td>
                  <td className="py-2 align-top text-slate-600">
                    {a.role?.nom ?? a.role_id}
                    {a.role?.code === 'parent' && (
                      <EnfantsAffectation affectation={a} onSaved={invalider} />
                    )}
                  </td>
                  <td className="py-2 align-top text-right">
                    <button
                      onClick={() => retirer.mutate(a.id)}
                      disabled={retirer.isPending}
                      className="rounded p-1.5 text-red-500 hover:bg-red-50"
                      title="Retirer ce rôle"
                    >
                      <Trash2 size={16} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : <p className="text-sm text-slate-400">Aucune affectation. Ajoutez un premier rôle ci-dessous.</p>}

        <div className="mt-6 border-t border-slate-100 pt-4">
          <h3 className="mb-3 text-sm font-semibold text-slate-700">Ajouter un rôle</h3>
          {societeCode && (
            <p className="mb-3 text-xs text-slate-400">Établissements limités à la société {societeCode} (un utilisateur ne peut appartenir qu'à une seule société).</p>
          )}
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-[220px] flex-1">
              <Select label="Établissement" value={nouvelEtab} onChange={(e) => setNouvelEtab(e.target.value)}>
                <option value="">— Choisir —</option>
                {etabs?.map((e) => <option key={e.code} value={e.code}>{e.intitule} ({e.code})</option>)}
              </Select>
            </div>
            <div className="min-w-[180px] flex-1">
              <Select label="Rôle" value={nouveauRole} onChange={(e) => setNouveauRole(e.target.value)}>
                <option value="">— Choisir —</option>
                {roles?.map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
              </Select>
            </div>
            <Button
              disabled={!nouvelEtab || !nouveauRole || ajouter.isPending}
              onClick={() => ajouter.mutate()}
            >
              {ajouter.isPending ? 'Ajout…' : 'Ajouter'}
            </Button>
          </div>
          {estRoleParent && (
            <div className="mt-3 max-w-md">
              <SelecteurEleves selection={enfants} onChange={setEnfants} />
            </div>
          )}
          {erreur && <p className="mt-2 text-sm text-red-600">{erreur}</p>}
        </div>
      </section>
    </div>
  )
}
