import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import {
  createAffectation,
  fetchAllEtablissements,
  fetchRoles,
  fetchUtilisateur,
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

export default function UtilisateurDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [nouvelEtab, setNouvelEtab] = useState('')
  const [nouveauRole, setNouveauRole] = useState('')
  const [erreur, setErreur] = useState(null)

  const { data: user, isLoading } = useQuery({ queryKey: ['utilisateurs', id], queryFn: () => fetchUtilisateur(id) })
  const societeCode = user?.societe_code || undefined
  const { data: etabs } = useQuery({ queryKey: ['etabs-affectation', societeCode], queryFn: () => fetchAllEtablissements(societeCode), enabled: !!user })
  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })

  const invalider = () => qc.invalidateQueries({ queryKey: ['utilisateurs', id] })

  const ajouter = useMutation({
    mutationFn: () => createAffectation({ rh_user_id: Number(id), etablissement_code: nouvelEtab, role_id: Number(nouveauRole) }),
    onSuccess: () => { invalider(); setNouvelEtab(''); setNouveauRole(''); setErreur(null) },
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
                  <td className="py-2 text-slate-800">{a.etablissement?.intitule ?? a.etablissement_code}</td>
                  <td className="py-2 text-slate-600">{a.societe_code}</td>
                  <td className="py-2 text-slate-600">{a.role?.nom ?? a.role_id}</td>
                  <td className="py-2 text-right">
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
          {erreur && <p className="mt-2 text-sm text-red-600">{erreur}</p>}
        </div>
      </section>
    </div>
  )
}
