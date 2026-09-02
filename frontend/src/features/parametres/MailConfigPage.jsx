import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import { fetchConfigMail, saveConfigMail } from './parametresApi'

export default function MailConfigPage() {
  const qc = useQueryClient()
  const [modifs, setModifs] = useState({})
  const [erreurs, setErreurs] = useState({})
  const [ok, setOk] = useState(false)

  const { data, isLoading } = useQuery({ queryKey: ['config-mail'], queryFn: fetchConfigMail })

  // Dérivé de la configuration chargée + les modifications en cours.
  const form = data ? { ...data, mot_de_passe: '', ...modifs } : null

  const enregistrer = useMutation({
    mutationFn: saveConfigMail,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['config-mail'] })
      setErreurs({}); setModifs({}); setOk(true); setTimeout(() => setOk(false), 4000)
    },
    onError: (e) => { setOk(false); setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }) },
  })

  const champ = (k, v) => setModifs((m) => ({ ...m, [k]: v }))

  if (isLoading || !form) return <p className="text-slate-400">Chargement…</p>

  return (
    <div className="max-w-2xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Messagerie sortante (SMTP)</h1>
        <p className="mt-1 text-sm text-slate-500">
          Compte utilisé pour envoyer les mails depuis Communication
          (source : ECONOMAT.T_MAIL_DIFFUSION).
        </p>
      </div>

      {ok && (
        <div className="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
          Configuration enregistrée.
        </div>
      )}

      <form onSubmit={(e) => { e.preventDefault(); enregistrer.mutate(form) }}
            className="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <Input label="Adresse d’envoi *" type="email" value={form.adresse ?? ''}
               onChange={(e) => champ('adresse', e.target.value)} error={erreurs.adresse?.[0]}
               placeholder="direction@monecole.ci" />
        <div className="grid grid-cols-2 gap-3">
          <Input label="Serveur SMTP *" value={form.serveur_smtp ?? ''} onChange={(e) => champ('serveur_smtp', e.target.value)}
                 error={erreurs.serveur_smtp?.[0]} placeholder="smtp.gmail.com" />
          <Input label="Port SMTP *" type="number" value={form.port_smtp ?? 587}
                 onChange={(e) => champ('port_smtp', e.target.value)} error={erreurs.port_smtp?.[0]} />
        </div>
        <Input label={form.mot_de_passe_defini ? 'Mot de passe (déjà défini — laisser vide pour conserver)' : 'Mot de passe'}
               type="password" value={form.mot_de_passe ?? ''} onChange={(e) => champ('mot_de_passe', e.target.value)}
               error={erreurs.mot_de_passe?.[0]} />
        <p className="text-xs text-slate-400">
          Le mot de passe n’est jamais réaffiché après enregistrement. Les envois utilisent TLS.
        </p>

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
