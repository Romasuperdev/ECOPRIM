import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import SelecteurEleves from './SelecteurEleves'
import { useAuthStore } from '../../store/authStore'
import {
  activerUtilisateur,
  createUtilisateur,
  desactiverUtilisateur,
  fetchAllEtablissements,
  fetchRoles,
  fetchUtilisateurs,
  updateUtilisateur,
} from './adminApi'

const VIDE = {
  login: '', mot_de_passe: '', nom: '', prenom: '', email: '',
  matricule: '', etab: '', contact: '', profil: '', code_app: '', super_admin: false,
  // Rôle & affectation — posés dans le même geste que la création (voir
  // UserController::store). C'est ici qu'on fait d'un compte un Enseignant ou un Parent :
  // sans ce rôle, le compte garde l'accès complet à l'application.
  etablissement_code: '', role_id: '',
}

export default function UtilisateurListPage() {
  // Les trois niveaux d'administrateur peuvent créer et gérer un compte — c'est le
  // quotidien d'un établissement d'inscrire ses propres enseignants et parents, pas une
  // prérogative réservée à la société. Le périmètre (quel établissement, quel rôle) reste
  // décidé par le serveur à chaque appel.
  const peutConsole = useAuthStore((s) => s.peutConsole)
  const superAdmin = useAuthStore((s) => s.superAdmin)
  const [page, setPage] = useState(1)
  const [q, setQ] = useState('')
  const [form, setForm] = useState(null)
  const [enfants, setEnfants] = useState([])
  const [erreurs, setErreurs] = useState({})
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['utilisateurs', page, q],
    queryFn: () => fetchUtilisateurs(page, q),
  })

  const { data: etablissements } = useQuery({
    queryKey: ['etabs-tous'],
    queryFn: () => fetchAllEtablissements(),
  })

  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })
  const roleChoisi = roles?.find((r) => String(r.id) === String(form?.role_id))
  const estRoleParent = roleChoisi?.code === 'parent'

  const invalider = () => qc.invalidateQueries({ queryKey: ['utilisateurs'] })

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id
      ? updateUtilisateur(v.id, v)
      : createUtilisateur({ ...v, eleves: estRoleParent ? enfants.map((e) => e.matricule) : undefined })),
    onSuccess: () => { invalider(); setForm(null); setEnfants([]); setErreurs({}) },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const basculer = useMutation({
    mutationFn: ({ id, actif }) => (actif ? desactiverUtilisateur(id) : activerUtilisateur(id)),
    onSuccess: invalider,
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Utilisateurs &amp; Accès</h1>
          <p className="mt-1 text-sm text-slate-500">
            Comptes de connexion (dbmasterbacou.RH_USER). La suppression est volontairement
            impossible : un compte retiré est désactivé.
          </p>
        </div>
        {peutConsole && (
          <Button onClick={() => { setErreurs({}); setEnfants([]); setForm({ ...VIDE }) }}>+ Nouvel utilisateur</Button>
        )}
      </div>

      <div className="mb-4 max-w-sm">
        <Input label="Rechercher" placeholder="Nom, login ou email…" value={q}
               onChange={(e) => { setPage(1); setQ(e.target.value) }} />
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Login</th>
              <th className="px-4 py-3 font-medium">Email</th>
              <th className="px-4 py-3 font-medium">Profil</th>
              <th className="px-4 py-3 font-medium">Actif</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucun utilisateur.</td></tr>
            )}
            {data?.data?.map((u) => (
              <tr key={u.id} className="hover:bg-slate-50">
                <td className="px-4 py-3">
                  <Link to={`/admin/utilisateurs/${u.id}`} className="font-medium text-primary-700 hover:underline">
                    {u.name}
                  </Link>
                  {u.super_admin && (
                    <span className="ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                      Super Admin
                    </span>
                  )}
                </td>
                <td className="px-4 py-3 text-slate-600">{u.login ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{u.email ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{u.profil ?? '—'}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${u.actif ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                    {u.actif ? 'Oui' : 'Non'}
                  </span>
                </td>
                <td className="px-4 py-3 text-right">
                  {peutConsole ? (
                    <div className="flex justify-end gap-2">
                      <Button variant="outline" className="!px-3 !py-1"
                              onClick={() => { setErreurs({}); setForm({ ...VIDE, ...u, mot_de_passe: '' }) }}>
                        Éditer
                      </Button>
                      <Button variant="outline" className="!px-3 !py-1" disabled={basculer.isPending}
                              onClick={() => basculer.mutate({ id: u.id, actif: u.actif })}>
                        {u.actif ? 'Désactiver' : 'Réactiver'}
                      </Button>
                    </div>
                  ) : (
                    <Link to={`/admin/utilisateurs/${u.id}`} className="text-sm text-primary-700 hover:underline">
                      Rôles
                    </Link>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>Précédent</Button>
          <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>Suivant</Button>
        </div>
      )}

      {form && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setForm(null)}>
          <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" onClick={(ev) => ev.stopPropagation()}>
            <h2 className="mb-4 text-lg font-bold text-slate-800">
              {form.id ? "Modifier l'utilisateur" : 'Nouvel utilisateur'}
            </h2>
            <form onSubmit={(ev) => { ev.preventDefault(); enregistrer.mutate(form) }} className="space-y-4">

              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Connexion</p>
              <div className="grid grid-cols-2 gap-3">
                <Input label="Login *" value={form.login ?? ''} onChange={(ev) => champ('login', ev.target.value)} error={erreurs.login?.[0]} />
                <Input label={form.id ? 'Mot de passe (laisser vide pour conserver)' : 'Mot de passe *'}
                       type="password" value={form.mot_de_passe ?? ''}
                       onChange={(ev) => champ('mot_de_passe', ev.target.value)} error={erreurs.mot_de_passe?.[0]} />
              </div>

              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Identité</p>
              <div className="grid grid-cols-2 gap-3">
                <Input label="Nom *" value={form.nom ?? ''} onChange={(ev) => champ('nom', ev.target.value)} error={erreurs.nom?.[0]} />
                <Input label="Prénom" value={form.prenom ?? ''} onChange={(ev) => champ('prenom', ev.target.value)} error={erreurs.prenom?.[0]} />
                <Input label="Email" value={form.email ?? ''} onChange={(ev) => champ('email', ev.target.value)} error={erreurs.email?.[0]} />
                <Input label="Matricule" value={form.matricule ?? ''} onChange={(ev) => champ('matricule', ev.target.value)} error={erreurs.matricule?.[0]} />
                <Input label="Contact" value={form.contact ?? ''} onChange={(ev) => champ('contact', ev.target.value)} error={erreurs.contact?.[0]} />
              </div>

              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Rattachement</p>
              <div className="grid grid-cols-2 gap-3">
                <Select label="Établissement" value={form.etab ?? ''} onChange={(ev) => champ('etab', ev.target.value)} error={erreurs.etab?.[0]}>
                  <option value="">— Aucun —</option>
                  {etablissements?.map((e) => (
                    <option key={e.code} value={e.code}>{e.intitule} ({e.code})</option>
                  ))}
                  {/* Valeur héritée de RH_USER qui ne correspond à aucun établissement connu :
                      on la conserve pour ne pas l'effacer en enregistrant. */}
                  {form.etab && !etablissements?.some((e) => e.code === form.etab) && (
                    <option value={form.etab}>{form.etab} (inconnu)</option>
                  )}
                </Select>
                <Input label="Profil" value={form.profil ?? ''} onChange={(ev) => champ('profil', ev.target.value)} error={erreurs.profil?.[0]} />
                <Input label="Application (CodeApp)" value={form.code_app ?? ''} onChange={(ev) => champ('code_app', ev.target.value)} error={erreurs.code_app?.[0]} />
              </div>

              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" checked={!!form.super_admin} onChange={(ev) => champ('super_admin', ev.target.checked)} />
                Super Admin (accès complet à la console)
              </label>

              {!form.id && (
                <>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Rôle (Enseignant, Parent, Secrétaire…)
                  </p>
                  <p className="-mt-2 text-xs text-slate-400">
                    C'est ce rôle qui décide de l'écran que verra ce compte : un compte affecté
                    du SEUL rôle Enseignant ou Parent est dirigé vers son propre portail
                    restreint (ses classes, ou les enfants rattachés ci-dessous) au lieu de
                    l'application complète.
                    {!superAdmin && ' Obligatoire pour créer un compte que vous pourrez retrouver ensuite.'}
                  </p>
                  <div className="grid grid-cols-2 gap-3">
                    <Select
                      label={!superAdmin ? 'Établissement *' : 'Établissement'}
                      value={form.etablissement_code ?? ''} error={erreurs.etablissement_code?.[0]}
                      onChange={(ev) => champ('etablissement_code', ev.target.value)}
                    >
                      <option value="">— Choisir —</option>
                      {etablissements?.map((e) => <option key={e.code} value={e.code}>{e.intitule} ({e.code})</option>)}
                    </Select>
                    <Select
                      label={!superAdmin ? 'Rôle *' : 'Rôle'}
                      value={form.role_id ?? ''} error={erreurs.role_id?.[0]}
                      onChange={(ev) => champ('role_id', ev.target.value)}
                    >
                      <option value="">— Choisir —</option>
                      {roles?.map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
                    </Select>
                  </div>
                  {estRoleParent && (
                    <div className="max-w-md">
                      <SelecteurEleves selection={enfants} onChange={setEnfants} />
                    </div>
                  )}
                </>
              )}

              <p className="text-xs text-slate-400">
                Le compte est enregistré dans RH_USER, partagée avec les autres applications de la suite.
                Le mot de passe est haché (bcrypt).
              </p>
              {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}

              <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="outline" onClick={() => setForm(null)}>Annuler</Button>
                <Button
                  type="submit"
                  disabled={enregistrer.isPending
                    || (!form.id && !superAdmin && (!form.etablissement_code || !form.role_id))}
                >
                  {enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer'}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
