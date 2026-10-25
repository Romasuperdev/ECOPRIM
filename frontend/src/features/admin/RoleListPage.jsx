import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, ShieldPlus, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import { GrilleFormulaire, RecapErreurs } from '../../components/ui/CarteFormulaire'
import { createRole, deleteRole, fetchRoles } from './adminApi'

export default function RoleListPage() {
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const qc = useQueryClient()

  const { data: roles, isLoading } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })
  const invalider = () => qc.invalidateQueries({ queryKey: ['roles'] })

  const creer = useMutation({
    mutationFn: createRole,
    onSuccess: () => { invalider(); setForm(null); setErreurs({}) },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({ mutationFn: deleteRole, onSuccess: invalider })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Rôles</h1>
          <p className="mt-1 text-sm text-slate-500">
            Catalogue des rôles attribuables aux utilisateurs, établissement par établissement.
            Ce que chaque rôle a le droit de faire se règle dans <Link to="/admin/permissions" className="text-primary-700 hover:underline">Permissions</Link>.
          </p>
        </div>
        <Button onClick={() => { setErreurs({}); setForm({ code: '', nom: '' }) }}>+ Nouveau rôle</Button>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-card">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Société</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={4} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
            {!isLoading && roles?.length === 0 && (
              <tr><td colSpan={4} className="px-4 py-6 text-center text-muted">
                Aucun rôle. Créez-en un, ou lancez <code>php artisan console:importer</code> pour semer le catalogue par défaut.
              </td></tr>
            )}
            {roles?.map((r) => (
              <tr key={r.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-mono text-xs text-slate-800">{r.code}</td>
                <td className="px-4 py-3 font-medium text-slate-700">{r.nom}</td>
                <td className="px-4 py-3 text-slate-500">
                  {r.societe_code ?? <span className="italic text-muted">Général</span>}
                </td>
                <td className="px-4 py-3 text-right">
                  <Link
                    to={`/admin/permissions?role=${r.id}`}
                    className="mr-1 inline-flex rounded p-1.5 text-slate-500 hover:bg-slate-100"
                    title="Voir et modifier les permissions de ce rôle"
                  >
                    <KeyRound size={16} />
                  </Link>
                  <button
                    onClick={() => supprimer.mutate(r.id)}
                    disabled={supprimer.isPending}
                    className="rounded p-1.5 text-red-500 hover:bg-red-50"
                    title="Retirer ce rôle du catalogue"
                  >
                    <Trash2 size={16} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="mt-3 text-xs text-muted">
        Retirer un rôle du catalogue n’efface aucun compte : seules les affectations qui l’utilisent
        deviennent invalides. Les rôles sont propres à NEXORA et ne modifient pas les comptes.
      </p>

      {form && (
        <ModaleFormulaire
          titrePage="Rôles"
          titre="Nouveau rôle"
          icon={ShieldPlus}
          onFermer={() => setForm(null)}
          onValider={() => creer.mutate(form)}
          enCours={creer.isPending}
          libelleValider="Créer"
        >
          <RecapErreurs erreurs={erreurs._} />
          <GrilleFormulaire colonnes={2}>
            <Input label="Code *" value={form.code} onChange={(ev) => champ('code', ev.target.value)}
                   error={erreurs.code?.[0]} placeholder="ex. surveillant" />
            <Input label="Nom *" value={form.nom} onChange={(ev) => champ('nom', ev.target.value)}
                   error={erreurs.nom?.[0]} placeholder="ex. Surveillant" />
          </GrilleFormulaire>
        </ModaleFormulaire>
      )}
    </div>
  )
}
