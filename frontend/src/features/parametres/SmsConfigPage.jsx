import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { fetchConfigSms, saveConfigSms } from './parametresApi'

export default function SmsConfigPage() {
  const qc = useQueryClient()
  // Formulaire DÉRIVÉ de la configuration chargée + les modifications en cours :
  // pas de recopie par effet, donc pas de course au chargement.
  const [modifs, setModifs] = useState({})
  const [erreurs, setErreurs] = useState({})
  const [ok, setOk] = useState(false)

  const { data, isLoading } = useQuery({ queryKey: ['config-sms'], queryFn: fetchConfigSms })

  const form = data ? { ...data, api_key: '', api_secret: '', ...modifs } : null

  const enregistrer = useMutation({
    mutationFn: saveConfigSms,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['config-sms'] })
      setErreurs({}); setModifs({}); setOk(true); setTimeout(() => setOk(false), 4000)
    },
    onError: (e) => { setOk(false); setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }) },
  })

  const champ = (k, v) => setModifs((m) => ({ ...m, [k]: v }))

  if (isLoading || !form) return <p className="text-slate-400">Chargement…</p>

  return (
    <div className="max-w-3xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Passerelle SMS</h1>
        <p className="mt-1 text-sm text-slate-500">
          Configuration de l’opérateur d’envoi de SMS (source : ECONOMAT.ECO_SMS_CONFIG).
          Les messages envoyés depuis Communication sont déposés dans la file <code>T_SMS</code>.
        </p>
      </div>

      {ok && (
        <div className="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
          Configuration enregistrée.
        </div>
      )}

      <form onSubmit={(e) => { e.preventDefault(); enregistrer.mutate(form) }}
            className="space-y-6 rounded-xl border border-slate-200 bg-white p-6">

        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
          <input type="checkbox" checked={!!form.actif} onChange={(e) => champ('actif', e.target.checked)} />
          Passerelle active (sans cela, aucun SMS ne peut être envoyé)
        </label>

        <div>
          <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Opérateur</p>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Nom de la configuration" value={form.nom ?? ''} onChange={(e) => champ('nom', e.target.value)}
                   error={erreurs.nom?.[0]} placeholder="ex. Orange CI production" />
            <Input label="Fournisseur" value={form.fournisseur ?? ''} onChange={(e) => champ('fournisseur', e.target.value)}
                   error={erreurs.fournisseur?.[0]} placeholder="ex. Orange, Twilio…" />
            <Select label="Environnement *" value={form.environnement ?? 'TEST'} onChange={(e) => champ('environnement', e.target.value)}
                    error={erreurs.environnement?.[0]}>
              <option value="TEST">Test</option>
              <option value="PROD">Production</option>
            </Select>
            <Input label="Expéditeur (SENDER_ID)" value={form.expediteur ?? ''} onChange={(e) => champ('expediteur', e.target.value)}
                   error={erreurs.expediteur?.[0]} placeholder="ex. NEXORA" />
            <Input label="Pays" value={form.pays ?? ''} onChange={(e) => champ('pays', e.target.value)} error={erreurs.pays?.[0]} />
          </div>
        </div>

        <div>
          <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Accès API</p>
          <div className="grid grid-cols-2 gap-3">
            <Input label="URL de l’API" value={form.api_url ?? ''} onChange={(e) => champ('api_url', e.target.value)}
                   error={erreurs.api_url?.[0]} placeholder="https://…" />
            <Input label={form.api_key_definie ? 'Clé API (déjà définie — laisser vide pour conserver)' : 'Clé API'}
                   type="password" value={form.api_key ?? ''} onChange={(e) => champ('api_key', e.target.value)}
                   error={erreurs.api_key?.[0]} />
            <Input label="Secret API (laisser vide pour conserver)" type="password" value={form.api_secret ?? ''}
                   onChange={(e) => champ('api_secret', e.target.value)} error={erreurs.api_secret?.[0]} />
          </div>
          <p className="mt-2 text-xs text-slate-400">
            La clé et le secret ne sont jamais réaffichés après enregistrement.
          </p>
        </div>

        <div>
          <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Options</p>
          <div className="space-y-2">
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={!!form.accuses_reception} onChange={(e) => champ('accuses_reception', e.target.checked)} />
              Demander les accusés de réception
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={!!form.sms_long} onChange={(e) => champ('sms_long', e.target.checked)} />
              Autoriser les SMS longs (concaténés)
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={!!form.notif_auto} onChange={(e) => champ('notif_auto', e.target.checked)} />
              Notifications automatiques (absences, résultats…)
            </label>
          </div>
        </div>

        <Input label="Description" value={form.description ?? ''} onChange={(e) => champ('description', e.target.value)} error={erreurs.description?.[0]} />

        {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}

        <div className="flex justify-end">
          <Button type="submit" disabled={enregistrer.isPending}>
            {enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer la configuration'}
          </Button>
        </div>
      </form>
    </div>
  )
}
