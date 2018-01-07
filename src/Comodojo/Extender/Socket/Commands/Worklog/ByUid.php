<?php namespace Comodojo\Extender\Socket\Commands\Worklog;

use \Comodojo\Daemon\Daemon;
use \Comodojo\RpcServer\Request\Parameters;
use \Comodojo\Extender\Worklog\Manager;
use \Comodojo\Extender\Transformers\WorklogTransformer;
use \League\Fractal\Manager as FractalManager;
use \League\Fractal\Resource\Item;

class ByUid {

    public static function execute(Parameters $params, Daemon $daemon) {

        $uid = $params->get('uid');

        $manager = new Manager(
            $daemon->getConfiguration(),
            $daemon->getLogger(),
            $daemon->getEvents()
        );

        $data = $manager->getOne(['uid' => $uid]);

        $resource = new Item($data, new WorklogTransformer);
        $fractal = new FractalManager();
        $data = $fractal->createData($resource)->toArray();

        return $data['data'];

    }

}
