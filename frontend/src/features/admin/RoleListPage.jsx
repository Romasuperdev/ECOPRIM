import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
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
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Rôles &amp; permissions</h1>
          <p className="mt-1 text-sm text-slate-500">
            Catalogue des rôles attribuables aux utilisateurs, établissement par établissement.
          </p>
        </div>
        <Button onClick={() => { setErreurs({}); setForm({ code: '', nom: '' }) }}>+ Nouveau rôle</Button>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={3} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && roles?.length === 0 && (
              <tr><td colSpan={3} className="px-4 py-6 text-center text-slate-400">
                Aucun rôle. Créez-en un, ou lancez <code>php artisan console:importer</code> pour semer le catalogue par défaut.
              </td></tr>
            )}
            {roles?.map((r) => (
              <tr key={r.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-mono text-xs text-slate-800">{r.code}</td>
                <td className="px-4 py-3 font-medium text-slate-700">{r.nom}</td>
                <td className="px-4 py-3 text-right">
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

      <p className="mt-3 text-xs text-slate-400">
        Retirer un rôle du catalogue n’efface aucun compte : seules les affectations qui l’utilisent
        deviennent invalides. Les rôles sont propres à NEXORA et n’altèrent pas RH_USER.
      </p>

      {form && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setForm(null)}>
          <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" onClick={(ev) => ev.stopPropagation()}>
            <h2 className="mb-4 text-lg font-bold text-slate-800">Nouveau rôle</h2>
            <form onSubmit={(ev) => { ev.preventDefault(); creer.mutate(form) }} className="space-y-3">
              <Input label="Code *" value={form.code} onChange={(ev) => champ('code', ev.target.value)}
                     error={erreurs.code?.[0]} placeholder="ex. surveillant" />
              <Input label="Nom *" value={form.nom} onChange={(ev) => champ('nom', ev.target.value)}
                     error={erreurs.nom?.[0]} placeholder="ex. Surveillant" />
              {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
              <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="outline" onClick={() => setForm(null)}>Annuler</Button>
                <Button type="submit" disabled={creer.isPending}>{creer.isPending ? 'Création…' : 'Créer'}</Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
